<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WorkEntry;

/**
 * Service für CSV-Export von Report-Daten
 */
class CsvExportService
{
    /**
     * CSV aus Report-Daten generieren
     *
     * @param array[] $entries Report-Einträge (Arrays aus ReportRepository)
     * @param bool $includeMemberColumn Mitglied-Spalte einbeziehen (für Prüfer/Admin)
     * @return string CSV-Inhalt mit UTF-8 BOM für Excel-Kompatibilität
     */
    public function generateWorkEntryCsv(array $entries, bool $includeMemberColumn): string
    {
        $output = fopen('php://memory', 'r+');
        if ($output === false) {
            return '';
        }

        // UTF-8 BOM für Excel-Kompatibilität
        fwrite($output, "\xEF\xBB\xBF");

        // Header-Zeile
        $headers = ['Antragsnr.', 'Datum'];
        if ($includeMemberColumn) {
            $headers[] = 'Mitglied';
            $headers[] = 'Mitgliedsnr.';
        }
        $headers = array_merge($headers, [
            'Kategorie',
            'Projekt',
            'Stunden',
            'Status',
            'Beschreibung',
            'Eingereicht am',
            'Geprüft von',
            'Geprüft am',
        ]);

        fputcsv($output, $headers, ';');

        // Datenzeilen
        foreach ($entries as $entry) {
            $row = [
                $entry['entry_number'] ?? '',
                $this->formatDate($entry['work_date'] ?? ''),
            ];

            if ($includeMemberColumn) {
                $row[] = $entry['user_name'] ?? '';
                $row[] = $entry['mitgliedsnummer'] ?? '';
            }

            $statusLabel = WorkEntry::STATUS_LABELS[$entry['status']] ?? ($entry['status'] ?? '');

            $row = array_merge($row, [
                $entry['category_name'] ?? '',
                $entry['project'] ?? '',
                number_format((float) ($entry['hours'] ?? 0), 2, ',', ''),
                $statusLabel,
                $entry['description'] ?? '',
                $this->formatDateTime($entry['submitted_at'] ?? null),
                $entry['reviewed_by_name'] ?? '',
                $this->formatDateTime($entry['reviewed_at'] ?? null),
            ]);

            fputcsv($output, $row, ';');
        }

        // Inhalt lesen
        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        return $content !== false ? $content : '';
    }

    /**
     * Datum im deutschen Format formatieren
     */
    private function formatDate(string $date): string
    {
        if ($date === '') {
            return '';
        }
        try {
            return (new \DateTime($date))->format('d.m.Y');
        } catch (\Throwable $e) {
            return $date;
        }
    }

    /**
     * Datum+Zeit im deutschen Format formatieren
     */
    private function formatDateTime(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '';
        }
        try {
            return (new \DateTime($datetime))->format('d.m.Y H:i');
        } catch (\Throwable $e) {
            return $datetime;
        }
    }
}
