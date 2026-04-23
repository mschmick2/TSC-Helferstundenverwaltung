<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invarianten fuer die gemeinsame Editor-Sidebar
 * events/_editor_sidebar.php (Modul 6 I7e-A Phase 2 + 2c).
 *
 * Sidebar hat drei Card-Panels:
 *   1. Event-Metadaten (Titel, Zeitraum, Ort, Status, Organisatoren).
 *   2. Belegungs-Zusammenfassung (Zusagen, Soll, Offen, Stunden, Status).
 *   3. Chronologische Aufgabenliste (Buttons mit
 *      data-sidebar-scroll-target, von event-task-tree.js konsumiert).
 *
 * Die Tests sichern:
 *   - XSS-Schutz ueber ViewHelper::e() auf allen User-Freitext-Feldern.
 *   - Panel-Struktur (drei <section class="card">-Bloecke).
 *   - Phase-2c-Bug-Fix: Panel-2 greift auf zusagen_aktiv, nicht auf
 *     helpers_total — letzteres ist jetzt die neue "Helfer-Soll"-Zeile.
 *   - Scroll-Highlight-Hooks: jeder Leaf-Eintrag in Panel-3 rendert
 *     data-sidebar-scroll-target mit der Task-ID (event-task-tree.js
 *     haengt sich an dieses Attribut).
 */
final class EditorSidebarInvariantsTest extends TestCase
{
    private const SIDEBAR_PATH =
        __DIR__ . '/../../../src/app/Views/events/_editor_sidebar.php';

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    // =========================================================================
    // Gruppe A — Struktur-Invarianten
    // =========================================================================

    public function test_sidebar_renders_aside_wrapper(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        self::assertStringContainsString(
            '<aside',
            $sidebar,
            'Sidebar muss das semantische <aside>-Element als Wurzel verwenden '
            . '(Accessibility: screen reader landmark).'
        );
        self::assertMatchesRegularExpression(
            '/aria-label="[^"]+"/',
            $sidebar,
            '<aside> braucht ein aria-label (sinnvoll beschriftet fuer Screenreader).'
        );
    }

    public function test_sidebar_has_three_card_sections(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        // Drei <section class="card ...">-Bloecke fuer die drei Panels.
        $count = preg_match_all(
            '/<section class="card\b/',
            $sidebar,
            $m
        );
        self::assertSame(
            3,
            $count,
            'Sidebar muss genau drei <section class="card">-Panels haben '
            . '(Event-Meta, Belegung, Chronologie). Gefunden: ' . (int) $count
        );
    }

    // =========================================================================
    // Gruppe B — XSS-Schutz (User-Freitext durch ViewHelper::e)
    // =========================================================================

    public function test_sidebar_escapes_event_title(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        self::assertMatchesRegularExpression(
            '/ViewHelper::e\(\s*\$event->getTitle\(\)\s*\)/',
            $sidebar,
            'Sidebar muss event->getTitle() durch ViewHelper::e() schicken (XSS-Schutz).'
        );
    }

    public function test_sidebar_escapes_event_location(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        // getLocation() kann null oder String sein, beide Wege muessen escaped werden.
        self::assertMatchesRegularExpression(
            '/ViewHelper::e\(\s*\$event->getLocation\(\)\s*\)/',
            $sidebar,
            'Sidebar muss event->getLocation() durch ViewHelper::e() schicken '
            . '(User-Freitext aus Event-Form).'
        );
    }

    public function test_sidebar_escapes_organizer_names(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        // Organisator-Namen kommen aus $organizers-Array mit 'vorname'/'nachname'.
        // Beide MUESSEN durch ViewHelper::e() laufen, sonst XSS via Vereinsdaten.
        self::assertMatchesRegularExpression(
            "/ViewHelper::e\\(\\s*\\n?\\s*\\(\\s*\\\$org\\['nachname'\\]/s",
            $sidebar,
            'Sidebar muss $org[\'nachname\'] durch ViewHelper::e() schicken.'
        );
        self::assertStringContainsString(
            "\$org['vorname']",
            $sidebar,
            'Sidebar muss $org[\'vorname\'] rendern.'
        );
    }

    public function test_sidebar_escapes_task_titles_in_panel_3(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        self::assertMatchesRegularExpression(
            '/ViewHelper::e\(\s*\$task->getTitle\(\)\s*\)/',
            $sidebar,
            'Panel-3 muss jeden Task-Titel durch ViewHelper::e() schicken.'
        );
    }

    // =========================================================================
    // Gruppe C — Panel-2 (Belegung) nach Phase-2c-Bug-Fix
    // =========================================================================

    public function test_panel2_reads_zusagen_aktiv_for_aktive_zusagen(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        // Die Zeile "Aktive Zusagen" MUSS auf $summary['zusagen_aktiv']
        // zugreifen. Vor Phase 2c stand dort faelschlich 'helpers_total',
        // was die Capacity-Target-Summe ist (Smoke-Bug: 144 statt 2).
        self::assertMatchesRegularExpression(
            "/\\\$summary\\[['\"]zusagen_aktiv['\"]\\]/",
            $sidebar,
            'Sidebar-Panel-2 muss $summary[\'zusagen_aktiv\'] lesen '
            . '(Phase-2c-Bug-Fix: "Aktive Zusagen"-Zeile).'
        );
    }

    public function test_panel2_has_helfer_soll_row(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        self::assertStringContainsString(
            'Helfer-Soll',
            $sidebar,
            'Sidebar-Panel-2 muss eine Zeile "Helfer-Soll" haben '
            . '(Phase 2c: Capacity-Target-Summe als eigener Eintrag, damit '
            . 'die alte Fehlbezeichnung nicht mehr auftauchen kann).'
        );
        self::assertMatchesRegularExpression(
            "/\\\$summary\\[['\"]helpers_total['\"]\\]/",
            $sidebar,
            'Helfer-Soll muss an $summary[\'helpers_total\'] haengen '
            . '(diese Summe ist die capacity_target-Gesamtsumme).'
        );
    }

    public function test_panel2_reads_open_slots_with_known_guard(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        // open_slots darf nur angezeigt werden, wenn open_slots_known true ist
        // (Summe ist sonst irrefuehrend — eine unbegrenzte Aufgabe kippt das).
        self::assertMatchesRegularExpression(
            "/\\\$summary\\[['\"]open_slots_known['\"]\\]/",
            $sidebar,
            'Sidebar muss open_slots_known pruefen, bevor open_slots als '
            . 'Zahl angezeigt wird (sonst irrefuehrend bei unbegrenzten Tasks).'
        );
        self::assertMatchesRegularExpression(
            "/\\\$summary\\[['\"]open_slots['\"]\\]/",
            $sidebar,
            'Sidebar muss $summary[\'open_slots\'] anzeigen, wenn bekannt.'
        );
    }

    public function test_panel2_renders_status_counts(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        // I7b3-Status-Verteilung (empty/partial/full) muss in der Belegungs-
        // Zusammenfassung sichtbar sein — das ist der korrekte Teil, der im
        // Smoke richtig angezeigt wurde.
        foreach (['empty', 'partial', 'full'] as $key) {
            self::assertStringContainsString(
                "'$key'",
                $sidebar,
                "Panel-2 muss status_counts['$key'] rendern (I7b3-Farbkodierung)."
            );
        }
    }

    public function test_panel2_formats_hours_with_german_number_format(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        // number_format(..., 2, ',', '.') fuer deutsche Zahlen.
        self::assertMatchesRegularExpression(
            "/number_format\\([^,]+,\\s*2\\s*,\\s*','\\s*,\\s*'\\.'\\s*\\)/",
            $sidebar,
            'Stunden-Summe muss mit number_format(..., 2, \',\', \'.\') '
            . 'rendern (deutsches Format).'
        );
    }

    // =========================================================================
    // Gruppe D — Panel-3 (Chronologische Task-Liste) mit Scroll-Target
    // =========================================================================

    public function test_panel3_iterates_flat_list(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        self::assertMatchesRegularExpression(
            '/foreach\s*\(\s*\$flatList\s+as\s+\$entry\s*\)/s',
            $sidebar,
            'Panel-3 muss ueber $flatList iterieren (chronologische Leaves '
            . 'aus flattenToList).'
        );
    }

    public function test_panel3_wraps_items_with_scroll_target(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        // event-task-tree.js liest data-sidebar-scroll-target="{taskId}" und
        // scrollt den Tree-Knoten in den Viewport. Ohne dieses Attribut
        // funktioniert die Phase-2-Scroll-Highlight-Integration nicht.
        self::assertStringContainsString(
            'data-sidebar-scroll-target=',
            $sidebar,
            'Panel-3 muss pro Leaf data-sidebar-scroll-target="{taskId}" '
            . 'rendern — event-task-tree.js haengt sich an dieses Attribut '
            . '(Phase 2 Scroll-Highlight).'
        );
    }

    public function test_panel3_scroll_target_uses_task_id(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        self::assertMatchesRegularExpression(
            '/data-sidebar-scroll-target="[^"]*\$task->getId\(\)/',
            $sidebar,
            'data-sidebar-scroll-target muss $task->getId() als Wert nutzen '
            . '(int-Cast ist OK, solange der Task-ID-Wert rauskommt).'
        );
    }

    public function test_panel3_renders_task_status_badge(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        // Die Status-Badge-Klasse aus I7b3 wird in Panel-3 wiederverwendet.
        self::assertStringContainsString(
            'task-status-badge--',
            $sidebar,
            'Panel-3 muss pro Leaf die I7b3-Status-Badge-Klasse rendern '
            . '(task-status-badge--<value>).'
        );
    }

    public function test_panel3_uses_badgeLabel_for_status_text(): void
    {
        $sidebar = $this->read(self::SIDEBAR_PATH);
        self::assertStringContainsString(
            'badgeLabel()',
            $sidebar,
            'Panel-3 muss den Badge-Text via TaskStatus::badgeLabel() rendern '
            . '(nicht hart-kodieren).'
        );
    }
}
