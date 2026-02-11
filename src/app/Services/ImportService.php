<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\SecurityHelper;
use App\Repositories\UserRepository;

/**
 * Service für CSV-Import von Mitgliedern
 */
class ImportService
{
    private const REQUIRED_FIELDS = ['mitgliedsnummer', 'nachname', 'vorname', 'email'];
    private const ALL_FIELDS = [
        'mitgliedsnummer', 'nachname', 'vorname', 'email',
        'strasse', 'plz', 'ort', 'telefon', 'eintrittsdatum',
    ];

    public function __construct(
        private UserRepository $userRepo,
        private SettingsService $settingsService,
        private EmailService $emailService,
        private AuditService $auditService,
        private \PDO $pdo,
        private string $baseUrl = ''
    ) {
    }

    /**
     * CSV-Import durchführen
     *
     * @return array{created: int, updated: int, errors: array<int, string>, skipped: int}
     */
    public function importCsv(string $csvContent, int $adminUserId): array
    {
        $result = ['created' => 0, 'updated' => 0, 'errors' => [], 'skipped' => 0];

        // UTF-8-Konvertierung
        $csvContent = $this->ensureUtf8($csvContent);

        // CSV parsen
        $lines = $this->parseCsvLines($csvContent);
        if (count($lines) < 2) {
            $result['errors'][1] = 'CSV-Datei enthält keine Daten (nur Header oder leer).';
            return $result;
        }

        // Header validieren
        $header = $lines[0];
        $delimiter = $this->detectDelimiter($header);
        $headerFields = array_map('trim', array_map('strtolower', str_getcsv($header, $delimiter)));

        foreach (self::REQUIRED_FIELDS as $field) {
            if (!in_array($field, $headerFields, true)) {
                $result['errors'][1] = "Pflichtfeld '{$field}' fehlt im CSV-Header.";
                return $result;
            }
        }

        $fieldMap = array_flip($headerFields);
        $expiryDays = $this->settingsService->getInvitationExpiryDays();

        $this->pdo->beginTransaction();
        try {
            for ($i = 1; $i < count($lines); $i++) {
                $line = trim($lines[$i]);
                if ($line === '') {
                    continue;
                }

                $lineNumber = $i + 1;
                $fields = str_getcsv($line, $delimiter);

                // Zeile validieren
                $rowErrors = $this->validateRow($fields, $fieldMap, $lineNumber);
                if (!empty($rowErrors)) {
                    $result['errors'][$lineNumber] = implode('; ', $rowErrors);
                    continue;
                }

                $rowData = $this->mapRowToData($fields, $fieldMap);

                // Duplikaterkennung
                $existing = $this->userRepo->findByMitgliedsnummerIncludeDeleted($rowData['mitgliedsnummer']);

                if ($existing !== null) {
                    // Update bestehender User
                    $this->userRepo->updateStammdaten((int) $existing['id'], $rowData);
                    $this->auditService->log(
                        'update',
                        'users',
                        (int) $existing['id'],
                        oldValues: $existing,
                        newValues: $rowData,
                        description: "CSV-Import: Stammdaten aktualisiert für {$rowData['mitgliedsnummer']}"
                    );
                    $result['updated']++;
                } else {
                    // Neuer User erstellen
                    $userId = $this->userRepo->createUser($rowData);

                    // Mitglied-Rolle zuweisen
                    $mitgliedRole = $this->userRepo->getRoleByName('mitglied');
                    if ($mitgliedRole !== null) {
                        $this->userRepo->replaceRoles($userId, [(int) $mitgliedRole['id']], $adminUserId);
                    }

                    // Einladung erstellen und senden
                    $token = SecurityHelper::generateToken();
                    $invitationId = $this->userRepo->createInvitation($userId, $token, $expiryDays, $adminUserId);

                    try {
                        $setupUrl = rtrim($this->baseUrl, '/') . '/setup-password/' . $token;
                        $this->emailService->sendInvitation(
                            $rowData['email'],
                            $rowData['vorname'],
                            $setupUrl
                        );
                        $this->userRepo->markInvitationSent($invitationId);
                    } catch (\Throwable $e) {
                        // E-Mail-Fehler nicht als Import-Fehler werten
                        $result['errors'][$lineNumber] = "Erstellt, aber Einladungs-E-Mail konnte nicht gesendet werden.";
                    }

                    $this->auditService->log(
                        'import',
                        'users',
                        $userId,
                        newValues: $rowData,
                        description: "CSV-Import: Neues Mitglied {$rowData['mitgliedsnummer']}"
                    );
                    $result['created']++;
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $result['errors'][0] = 'Import fehlgeschlagen: ' . $e->getMessage();
        }

        // Import-Gesamtvorgang im Audit-Trail
        $this->auditService->log(
            'import',
            'users',
            metadata: [
                'created' => $result['created'],
                'updated' => $result['updated'],
                'errors' => count($result['errors']),
            ],
            description: "CSV-Import abgeschlossen: {$result['created']} erstellt, {$result['updated']} aktualisiert, " . count($result['errors']) . " Fehler"
        );

        return $result;
    }

    /**
     * UTF-8-Konvertierung sicherstellen
     */
    private function ensureUtf8(string $content): string
    {
        // BOM entfernen
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);

        if ($encoding !== false && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        return $content;
    }

    /**
     * CSV-Zeilen aufteilen
     *
     * @return string[]
     */
    private function parseCsvLines(string $content): array
    {
        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        return explode("\n", $content);
    }

    /**
     * Delimiter erkennen (Komma oder Semikolon)
     */
    private function detectDelimiter(string $headerLine): string
    {
        $semicolonCount = substr_count($headerLine, ';');
        $commaCount = substr_count($headerLine, ',');
        return $semicolonCount > $commaCount ? ';' : ',';
    }

    /**
     * Zeile validieren
     *
     * @return string[]
     */
    private function validateRow(array $fields, array $fieldMap, int $lineNumber): array
    {
        $errors = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            $idx = $fieldMap[$field] ?? null;
            if ($idx === null || !isset($fields[$idx]) || trim($fields[$idx]) === '') {
                $errors[] = "Pflichtfeld '{$field}' ist leer";
            }
        }

        // E-Mail validieren
        $emailIdx = $fieldMap['email'] ?? null;
        if ($emailIdx !== null && isset($fields[$emailIdx]) && trim($fields[$emailIdx]) !== '') {
            if (!filter_var(trim($fields[$emailIdx]), FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Ungültige E-Mail-Adresse";
            }
        }

        // Eintrittsdatum validieren (wenn vorhanden)
        $datumIdx = $fieldMap['eintrittsdatum'] ?? null;
        if ($datumIdx !== null && isset($fields[$datumIdx]) && trim($fields[$datumIdx]) !== '') {
            $date = trim($fields[$datumIdx]);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $errors[] = "Ungültiges Datumsformat für 'eintrittsdatum' (erwartet: JJJJ-MM-TT)";
            }
        }

        return $errors;
    }

    /**
     * CSV-Zeile auf Datenfelder mappen
     */
    private function mapRowToData(array $fields, array $fieldMap): array
    {
        $data = [];
        foreach (self::ALL_FIELDS as $field) {
            $idx = $fieldMap[$field] ?? null;
            $data[$field] = ($idx !== null && isset($fields[$idx])) ? trim($fields[$idx]) : null;
        }

        // Leere Strings zu null konvertieren
        foreach ($data as $key => $value) {
            if ($value === '') {
                $data[$key] = null;
            }
        }

        return $data;
    }
}
