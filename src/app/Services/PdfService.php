<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WorkEntry;
use TCPDF;

/**
 * Service für PDF-Generierung mit TCPDF
 */
class PdfService
{
    public function __construct(
        private SettingsService $settingsService
    ) {
    }

    /**
     * Arbeitsstunden-Report als PDF generieren
     *
     * @return string PDF-Inhalt als Binary-String
     */
    public function generateWorkEntryReport(
        array $entries,
        array $summary,
        array $filters,
        string $userName,
        bool $includeMemberColumn
    ): string {
        $vereinsname = $this->settingsService->getString('vereinsname', 'Verein');
        $logoPath = $this->settingsService->get('vereinslogo_path');

        // TCPDF-Instanz erstellen (Querformat für ausreichend Spaltenbreite)
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);

        // Dokument-Metadaten
        $pdf->SetCreator('VAES - Helferstundenverwaltung');
        $pdf->SetAuthor($userName);
        $pdf->SetTitle('Arbeitsstunden-Report');

        // Header/Footer deaktivieren (eigene Implementierung)
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // Seitenränder
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 15);

        // Erste Seite
        $pdf->AddPage();

        // ===== Header =====
        $this->renderHeader($pdf, $vereinsname, $logoPath, $userName);

        // ===== Filter-Zusammenfassung =====
        $this->renderFilterSummary($pdf, $filters);

        // ===== Zusammenfassung =====
        $this->renderSummary($pdf, $summary);

        // ===== Tabelle =====
        $this->renderTable($pdf, $entries, $includeMemberColumn);

        // ===== Footer auf jeder Seite =====
        $totalPages = $pdf->getNumPages();
        for ($i = 1; $i <= $totalPages; $i++) {
            $pdf->setPage($i);
            $pdf->SetY(-12);
            $pdf->SetFont('helvetica', '', 7);
            $pdf->Cell(0, 5, 'Seite ' . $i . ' von ' . $totalPages . ' | Erstellt: ' . date('d.m.Y H:i'), 0, 0, 'C');
        }

        return $pdf->Output('', 'S');
    }

    /**
     * Header rendern
     */
    private function renderHeader(TCPDF $pdf, string $vereinsname, ?string $logoPath, string $userName): void
    {
        // Logo (falls vorhanden und Datei existiert)
        $logoRendered = false;
        if ($logoPath !== null && $logoPath !== '' && file_exists($logoPath)) {
            try {
                $pdf->Image($logoPath, 10, 10, 25, 0, '', '', '', true, 300);
                $logoRendered = true;
            } catch (\Throwable $e) {
                // Logo-Fehler ignorieren, Report trotzdem generieren
            }
        }

        $xStart = $logoRendered ? 40 : 10;

        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetXY($xStart, 10);
        $pdf->Cell(0, 7, $vereinsname, 0, 1);

        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetX($xStart);
        $pdf->Cell(0, 6, 'Arbeitsstunden-Report', 0, 1);

        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetX($xStart);
        $pdf->Cell(0, 5, 'Erstellt von: ' . $userName . ' am ' . date('d.m.Y H:i'), 0, 1);

        $pdf->Ln(3);
    }

    /**
     * Filter-Zusammenfassung rendern
     */
    private function renderFilterSummary(TCPDF $pdf, array $filters): void
    {
        $parts = [];

        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
            $from = !empty($filters['date_from']) ? $this->formatDate($filters['date_from']) : 'Beginn';
            $to = !empty($filters['date_to']) ? $this->formatDate($filters['date_to']) : 'Heute';
            $parts[] = "Zeitraum: {$from} - {$to}";
        }

        if (!empty($filters['status'])) {
            $label = WorkEntry::STATUS_LABELS[$filters['status']] ?? $filters['status'];
            $parts[] = "Status: {$label}";
        }

        if (!empty($filters['category_name'])) {
            $parts[] = "Kategorie: {$filters['category_name']}";
        }

        if (!empty($filters['project'])) {
            $parts[] = "Projekt: {$filters['project']}";
        }

        if (!empty($filters['member_name'])) {
            $parts[] = "Mitglied: {$filters['member_name']}";
        }

        if (!empty($parts)) {
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->Cell(0, 5, 'Filter: ' . implode(' | ', $parts), 0, 1);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Ln(2);
        }
    }

    /**
     * Zusammenfassung rendern
     */
    private function renderSummary(TCPDF $pdf, array $summary): void
    {
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(240, 240, 240);

        $summaryText = sprintf(
            'Gesamt: %s Stunden | %d Einträge',
            number_format($summary['total_hours'], 2, ',', '.'),
            $summary['entry_count']
        );

        // Status-Verteilung
        if (!empty($summary['count_by_status'])) {
            $statusParts = [];
            foreach ($summary['count_by_status'] as $row) {
                $label = WorkEntry::STATUS_LABELS[$row['status']] ?? $row['status'];
                $statusParts[] = "{$label}: {$row['cnt']}";
            }
            $summaryText .= ' (' . implode(', ', $statusParts) . ')';
        }

        $pdf->Cell(0, 6, $summaryText, 0, 1, 'L', true);
        $pdf->Ln(3);
    }

    /**
     * Eintrags-Tabelle rendern
     */
    private function renderTable(TCPDF $pdf, array $entries, bool $includeMemberColumn): void
    {
        if (empty($entries)) {
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->Cell(0, 8, 'Keine Einträge gefunden.', 0, 1, 'C');
            return;
        }

        // Spaltenbreiten definieren (Querformat = 277mm nutzbar)
        if ($includeMemberColumn) {
            $colWidths = [22, 20, 40, 30, 30, 18, 22, 95];
            $headers = ['Nr.', 'Datum', 'Mitglied', 'Kategorie', 'Projekt', 'Stunden', 'Status', 'Beschreibung'];
        } else {
            $colWidths = [25, 22, 35, 35, 20, 25, 115];
            $headers = ['Nr.', 'Datum', 'Kategorie', 'Projekt', 'Stunden', 'Status', 'Beschreibung'];
        }

        // Tabellen-Header
        $this->renderTableHeader($pdf, $headers, $colWidths);

        // Tabellen-Zeilen
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(0, 0, 0);
        $fill = false;

        foreach ($entries as $entry) {
            $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

            // Prüfen ob genug Platz auf der Seite
            if ($pdf->GetY() > $pdf->getPageHeight() - 20) {
                $pdf->AddPage();
                $this->renderTableHeader($pdf, $headers, $colWidths);
                $pdf->SetFont('helvetica', '', 7);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);
            }

            $statusLabel = WorkEntry::STATUS_LABELS[$entry['status']] ?? $entry['status'];
            $hours = number_format((float) ($entry['hours'] ?? 0), 2, ',', '.');
            $description = mb_substr($entry['description'] ?? '', 0, 80);

            if ($includeMemberColumn) {
                $pdf->Cell($colWidths[0], 5, $entry['entry_number'] ?? '', 1, 0, 'L', true);
                $pdf->Cell($colWidths[1], 5, $this->formatDate($entry['work_date'] ?? ''), 1, 0, 'L', true);
                $pdf->Cell($colWidths[2], 5, $entry['user_name'] ?? '', 1, 0, 'L', true);
                $pdf->Cell($colWidths[3], 5, $entry['category_name'] ?? '-', 1, 0, 'L', true);
                $pdf->Cell($colWidths[4], 5, $entry['project'] ?? '-', 1, 0, 'L', true);
                $pdf->Cell($colWidths[5], 5, $hours, 1, 0, 'R', true);
                $pdf->Cell($colWidths[6], 5, $statusLabel, 1, 0, 'L', true);
                $pdf->Cell($colWidths[7], 5, $description, 1, 1, 'L', true);
            } else {
                $pdf->Cell($colWidths[0], 5, $entry['entry_number'] ?? '', 1, 0, 'L', true);
                $pdf->Cell($colWidths[1], 5, $this->formatDate($entry['work_date'] ?? ''), 1, 0, 'L', true);
                $pdf->Cell($colWidths[2], 5, $entry['category_name'] ?? '-', 1, 0, 'L', true);
                $pdf->Cell($colWidths[3], 5, $entry['project'] ?? '-', 1, 0, 'L', true);
                $pdf->Cell($colWidths[4], 5, $hours, 1, 0, 'R', true);
                $pdf->Cell($colWidths[5], 5, $statusLabel, 1, 0, 'L', true);
                $pdf->Cell($colWidths[6], 5, $description, 1, 1, 'L', true);
            }

            $fill = !$fill;
        }

        // Summenzeile
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(220, 220, 220);

        $totalHours = 0.0;
        foreach ($entries as $entry) {
            $totalHours += (float) ($entry['hours'] ?? 0);
        }
        $totalFormatted = number_format($totalHours, 2, ',', '.');

        if ($includeMemberColumn) {
            $sumWidth = $colWidths[0] + $colWidths[1] + $colWidths[2] + $colWidths[3] + $colWidths[4];
            $pdf->Cell($sumWidth, 6, 'Summe:', 1, 0, 'R', true);
            $pdf->Cell($colWidths[5], 6, $totalFormatted, 1, 0, 'R', true);
            $pdf->Cell($colWidths[6] + $colWidths[7], 6, '', 1, 1, 'L', true);
        } else {
            $sumWidth = $colWidths[0] + $colWidths[1] + $colWidths[2] + $colWidths[3];
            $pdf->Cell($sumWidth, 6, 'Summe:', 1, 0, 'R', true);
            $pdf->Cell($colWidths[4], 6, $totalFormatted, 1, 0, 'R', true);
            $pdf->Cell($colWidths[5] + $colWidths[6], 6, '', 1, 1, 'L', true);
        }
    }

    /**
     * Tabellen-Header rendern (wiederverwendbar für Seitenumbrüche)
     */
    private function renderTableHeader(TCPDF $pdf, array $headers, array $colWidths): void
    {
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(66, 66, 66);
        $pdf->SetTextColor(255, 255, 255);

        $headerCount = count($headers);
        for ($i = 0; $i < $headerCount; $i++) {
            $align = $headers[$i] === 'Stunden' ? 'R' : 'L';
            $pdf->Cell($colWidths[$i], 6, $headers[$i], 1, 0, $align, true);
        }
        $pdf->Ln();
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
}
