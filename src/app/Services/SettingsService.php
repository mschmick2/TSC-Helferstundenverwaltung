<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;

/**
 * Service für typisierte Systemeinstellungen mit Request-Level-Cache
 */
class SettingsService
{
    /** @var array<string, ?string>|null */
    private ?array $cache = null;

    public function __construct(
        private SettingsRepository $settingsRepo,
        private AuditService $auditService
    ) {
    }

    /**
     * Einstellung als String abrufen
     */
    public function get(string $key, ?string $default = null): ?string
    {
        $this->loadCache();
        return array_key_exists($key, $this->cache) ? $this->cache[$key] : $default;
    }

    /**
     * Einstellung als Integer abrufen
     */
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key);
        return $value !== null ? (int) $value : $default;
    }

    /**
     * Einstellung als Boolean abrufen
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Einstellung als String abrufen (mit leerem String als Default)
     */
    public function getString(string $key, string $default = ''): string
    {
        return $this->get($key) ?? $default;
    }

    /**
     * Einstellung setzen (mit Audit-Log)
     */
    public function set(string $key, ?string $value, int $userId): void
    {
        $oldValue = $this->get($key);

        if ($oldValue === $value) {
            return;
        }

        $this->settingsRepo->update($key, $value, $userId);
        $this->auditService->logConfigChange($key, $oldValue, $value);

        // Cache aktualisieren
        if ($this->cache !== null) {
            $this->cache[$key] = $value;
        }
    }

    /**
     * Mehrere Einstellungen auf einmal setzen
     *
     * @param array<string, ?string> $keyValuePairs
     */
    public function updateMultiple(array $keyValuePairs, int $userId): void
    {
        foreach ($keyValuePairs as $key => $value) {
            $this->set($key, $value, $userId);
        }
    }

    /**
     * Sind Soll-Stunden aktiviert?
     */
    public function isTargetHoursEnabled(): bool
    {
        return $this->getBool('target_hours_enabled');
    }

    /**
     * Standard-Sollstunden pro Jahr
     */
    public function getDefaultTargetHours(): int
    {
        return $this->getInt('target_hours_default', 20);
    }

    /**
     * Gültigkeit von Einladungslinks in Tagen
     */
    public function getInvitationExpiryDays(): int
    {
        return $this->getInt('invitation_expiry_days', 7);
    }

    /**
     * Feldkonfiguration für Stundenerfassung abrufen (REQ-14.2)
     *
     * @return array<string, string> Mapping: field_name => 'required'|'optional'|'hidden'
     */
    public function getFieldConfig(): array
    {
        return [
            'work_date' => $this->getString('field_datum_required', 'required'),
            'time_from' => $this->getString('field_zeit_von_required', 'optional'),
            'time_to' => $this->getString('field_zeit_bis_required', 'optional'),
            'hours' => $this->getString('field_stunden_required', 'required'),
            'category_id' => $this->getString('field_kategorie_required', 'required'),
            'project' => $this->getString('field_projekt_required', 'optional'),
            'description' => $this->getString('field_beschreibung_required', 'optional'),
        ];
    }

    /**
     * Cache leeren (z.B. nach Bulk-Update)
     */
    public function clearCache(): void
    {
        $this->cache = null;
    }

    /**
     * Cache bei Bedarf laden
     */
    private function loadCache(): void
    {
        if ($this->cache !== null) {
            return;
        }

        $allSettings = $this->settingsRepo->findAll();
        $this->cache = [];
        foreach ($allSettings as $key => $row) {
            $this->cache[$key] = $row['setting_value'];
        }
    }
}
