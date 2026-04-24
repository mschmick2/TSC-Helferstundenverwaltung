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
    /** I7e-B.0.1: Helper wurden in Traits extrahiert. */
    private const TRAIT_EVENT_TREE_ACTION_HELPERS =
        __DIR__ . '/../../../src/app/Controllers/Concerns/EventTreeActionHelpers.php';

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
        // I7e-B.0.1: Helper liegt im Trait EventTreeActionHelpers.
        $body = $this->methodBody(
            $this->read(self::TRAIT_EVENT_TREE_ACTION_HELPERS),
            'computeBelegungsSummary'
        );
        self::assertNotSame(
            '',
            $body,
            'Helper computeBelegungsSummary() muss im EventTreeActionHelpers-Trait existieren.'
        );
    }

    public function test_computeBelegungsSummary_returns_zusagen_aktiv_key(): void
    {
        $body = $this->methodBody(
            $this->read(self::TRAIT_EVENT_TREE_ACTION_HELPERS),
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
            $this->read(self::TRAIT_EVENT_TREE_ACTION_HELPERS),
            'computeBelegungsSummary'
        );
        self::assertMatchesRegularExpression(
            '/array_sum\s*\(\s*array_map\s*\(\s*[\'"]intval[\'"]\s*,\s*\$assignmentCounts\s*\)\s*\)/',
            $body,
            'computeBelegungsSummary() muss zusagen_aktiv via array_sum(array_map'
            . "('intval', \$assignmentCounts)) berechnen."
        );
    }

    public function test_trait_computeBelegungsSummary_returns_all_required_keys(): void
    {
        // I7e-B.0.1: Nach der Trait-Extraktion gibt es nur noch EINE
        // Implementierung (im Trait). Der vorherige Duplikat-Semantik-
        // Check zwischen Admin und Organizer ist damit obsolet. Wir
        // pruefen stattdessen den Trait direkt auf die erwarteten Keys.
        $body = $this->methodBody(
            $this->read(self::TRAIT_EVENT_TREE_ACTION_HELPERS),
            'computeBelegungsSummary'
        );
        self::assertNotSame('', $body, 'computeBelegungsSummary fehlt im Trait.');

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
                $body,
                "Trait-computeBelegungsSummary muss Key '$key' im Return-Array fuehren."
            );
        }
    }
}
