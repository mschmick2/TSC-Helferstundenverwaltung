<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Statische Code-Invarianten fuer Modul 6 I7e-A — EventAdminController
 * neue showEditor-Action (Phase 1) + Sidebar-Daten-Loading (Phase 2) +
 * Sidebar-Label-Bug-Fix (Phase 2c).
 *
 * Die Tree-Actions auf Admin-Seite bleiben unveraendert und werden
 * weiterhin von EventAdminControllerTreeInvariantsTest abgedeckt.
 * Diese Datei deckt spezifisch die neue showEditor-Route
 * (GET /admin/events/{id}/editor) und die computeBelegungsSummary-
 * Duplikat-Semantik ab. Wichtig: solange die Trait-Extraktion
 * (Follow-up n) nicht passiert ist, MUSS diese Methode identisch zur
 * OrganizerEventEditController-Version implementiert sein.
 */
final class EventAdminControllerEditorInvariantsTest extends TestCase
{
    private const CONTROLLER_PATH =
        __DIR__ . '/../../../src/app/Controllers/EventAdminController.php';

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    private function methodBody(string $code, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/')
            . '\s*\([^{]*\{(.*?)(?=private function |public function |protected function |\}\s*$)/s';
        if (preg_match($pattern, $code, $m)) {
            return $m[1];
        }
        return '';
    }

    // =========================================================================
    // Gruppe A — showEditor-Action-Authorization (Admin-Weg)
    // =========================================================================

    public function test_showEditor_action_exists(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        self::assertNotSame(
            '',
            $body,
            'EventAdminController::showEditor() muss existieren (I7e-A Phase 1).'
        );
    }

    public function test_showEditor_flag_check_precedes_authorization(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        $posFlag = strpos($body, 'treeEditorEnabled');
        $posAuth = strpos($body, 'assertEventEditPermission');

        self::assertNotFalse(
            $posFlag,
            'showEditor() muss treeEditorEnabled()-Check rufen.'
        );
        self::assertNotFalse(
            $posAuth,
            'showEditor() muss assertEventEditPermission() rufen.'
        );
        self::assertLessThan(
            $posAuth,
            $posFlag,
            'showEditor(): Flag-Check muss VOR Authorization laufen, sonst '
            . 'leakt die Feature-Existenz (404 vs. 403-Unterschied).'
        );
    }

    public function test_showEditor_calls_assertEventEditPermission(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        self::assertStringContainsString(
            'assertEventEditPermission',
            $body,
            'showEditor() muss assertEventEditPermission() aufrufen — die '
            . 'event_admin-RoleMiddleware greift schon auf der Route-Group, '
            . 'aber assertEventEditPermission akzeptiert zusaetzlich Organisator-'
            . 'Membership und wirft AuthorizationException fuer Fremd-User.'
        );
    }

    // =========================================================================
    // Gruppe B — showEditor-Sidebar-Daten-Loading (Phase 2, Spiegel Organizer)
    // =========================================================================

    public function test_showEditor_loads_tree_data(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        self::assertMatchesRegularExpression(
            '/\$this->treeAggregator->buildTree\s*\(/',
            $body,
            'showEditor() muss treeAggregator->buildTree() aufrufen — der Tree '
            . 'wird Server-seitig vor-aggregiert (Phase 2 I7e-A).'
        );
    }

    public function test_showEditor_loads_flat_list_for_sidebar(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        self::assertMatchesRegularExpression(
            '/\$this->treeAggregator->flattenToList\s*\(/',
            $body,
            'showEditor() muss flattenToList() aufrufen — Basis fuer Sidebar-'
            . 'Panel-3 (chronologische Task-Liste) und computeBelegungsSummary.'
        );
    }

    public function test_showEditor_loads_organizers_via_listForEvent(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        self::assertStringContainsString(
            'organizerRepo->listForEvent(',
            $body,
            'showEditor() muss organizerRepo->listForEvent() aufrufen '
            . '(Sidebar-Panel-1-Metadaten).'
        );
    }

    public function test_showEditor_loads_task_categories(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        self::assertStringContainsString(
            'categoryRepo->findAllActive(',
            $body,
            'showEditor() muss categoryRepo->findAllActive() aufrufen '
            . '(Kategorien fuer data-categories-Attribut am Tree-Widget).'
        );
    }

    public function test_showEditor_calls_computeBelegungsSummary(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        self::assertStringContainsString(
            '$this->computeBelegungsSummary(',
            $body,
            'showEditor() muss computeBelegungsSummary() aufrufen '
            . '(Sidebar-Panel-2-Zahlen).'
        );
    }

    public function test_showEditor_passes_sidebar_data_to_view(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        foreach (['treeData', 'flatList', 'summary', 'organizers', 'taskCategories', 'csrfTokenString'] as $key) {
            self::assertMatchesRegularExpression(
                "/'$key'\\s*=>/",
                $body,
                "showEditor() muss '$key' an die View uebergeben — ohne diesen "
                . "Key rendert die Sidebar bzw. der Tree leere Felder."
            );
        }
    }

    public function test_showEditor_renders_admin_editor_view(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        self::assertStringContainsString(
            "'admin/events/editor'",
            $body,
            'showEditor() rendert die Admin-Container-View '
            . '(admin/events/editor.php), NICHT die Organizer-Variante.'
        );
    }

    // =========================================================================
    // Gruppe C — computeBelegungsSummary (Duplikat-Semantik zu Organizer)
    // =========================================================================

    public function test_computeBelegungsSummary_exists(): void
    {
        $body = $this->methodBody(
            $this->read(self::CONTROLLER_PATH),
            'computeBelegungsSummary'
        );
        self::assertNotSame(
            '',
            $body,
            'Private Helper computeBelegungsSummary() fehlt (Phase 2/2c).'
        );
    }

    public function test_computeBelegungsSummary_returns_zusagen_aktiv_key(): void
    {
        $body = $this->methodBody(
            $this->read(self::CONTROLLER_PATH),
            'computeBelegungsSummary'
        );
        self::assertMatchesRegularExpression(
            "/'zusagen_aktiv'\\s*=>/",
            $body,
            'computeBelegungsSummary() muss einen zusagen_aktiv-Schluessel '
            . 'liefern (Phase-2c-Fix: "Aktive Zusagen"-Sidebar-Zeile).'
        );
    }

    public function test_computeBelegungsSummary_uses_array_sum_on_assignmentCounts(): void
    {
        $body = $this->methodBody(
            $this->read(self::CONTROLLER_PATH),
            'computeBelegungsSummary'
        );
        self::assertMatchesRegularExpression(
            '/array_sum\s*\(\s*array_map\s*\(\s*[\'"]intval[\'"]\s*,\s*\$assignmentCounts\s*\)\s*\)/',
            $body,
            'computeBelegungsSummary() muss zusagen_aktiv via array_sum(array_map'
            . "('intval', \$assignmentCounts)) berechnen (siehe Organizer-"
            . 'Counterpart; Duplikat-Semantik bis Trait-Extraktion).'
        );
    }

    public function test_computeBelegungsSummary_returns_same_keys_as_organizer(): void
    {
        // Duplikat-Semantik-Check: beide computeBelegungsSummary-Implementierungen
        // muessen identische Rueckgabe-Keys haben, sonst bricht der View (einer
        // der Editor-Controller liefert Keys, die die Sidebar erwartet, der
        // andere nicht). Regression: Trait-Extraktion (Follow-up n) darf erst
        // passieren, wenn beide Methoden wirklich identisch sind.
        $adminBody = $this->methodBody(
            $this->read(self::CONTROLLER_PATH),
            'computeBelegungsSummary'
        );
        $organizerBody = $this->methodBody(
            (string) file_get_contents(
                __DIR__ . '/../../../src/app/Controllers/OrganizerEventEditController.php'
            ),
            'computeBelegungsSummary'
        );
        self::assertNotSame('', $adminBody, 'Admin-computeBelegungsSummary fehlt.');
        self::assertNotSame('', $organizerBody, 'Organizer-computeBelegungsSummary fehlt.');

        $expectedKeys = [
            'leaf_count',
            'group_count',
            'helpers_total',
            'zusagen_aktiv',
            'open_slots',
            'open_slots_known',
            'hours_default_total',
            'status_counts',
        ];
        foreach ($expectedKeys as $key) {
            self::assertStringContainsString(
                "'$key'",
                $adminBody,
                "Admin-computeBelegungsSummary muss Key '$key' im Return-Array "
                . "fuehren (Duplikat-Semantik zum Organizer-Counterpart)."
            );
            self::assertStringContainsString(
                "'$key'",
                $organizerBody,
                "Organizer-computeBelegungsSummary muss Key '$key' im Return-Array "
                . "fuehren (Duplikat-Semantik zum Admin-Counterpart)."
            );
        }
    }
}
