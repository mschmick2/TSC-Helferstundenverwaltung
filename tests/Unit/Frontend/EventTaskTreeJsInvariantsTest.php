<?php

declare(strict_types=1);

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invarianten fuer src/public/js/event-task-tree.js —
 * spezifisch die Phase-2/2c-Ergaenzungen des non-modalen Editors:
 *
 *   - initSidebarScrollHighlight (Phase 2): reagiert auf
 *     [data-sidebar-scroll-target] aus der Sidebar.
 *   - initTreeCollapseControls (Phase 2c): Per-Node-Toggle ueber
 *     [data-action="toggle-node"], globales Expand/Collapse-All
 *     ueber [data-action="expand-all"] / "collapse-all".
 *   - boot() haengt alle drei Init-Funktionen ein.
 *
 * Die grundlegenden Tree-Actions (Sortable, Modal, Drag&Drop) werden
 * weiterhin von EventAdminControllerTreeInvariantsTest::test_no_raw_
 * innerHTML_on_user_text_in_js abgedeckt. Diese Datei prueft spezifisch
 * die I7e-A-Erweiterungen.
 */
final class EventTaskTreeJsInvariantsTest extends TestCase
{
    private const JS_KERN = __DIR__ . '/../../../src/public/js/event-task-tree.js';

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    // =========================================================================
    // Gruppe A — Init-Funktionen vorhanden und in boot() registriert
    // =========================================================================

    public function test_js_defines_initSidebarScrollHighlight(): void
    {
        $js = $this->read(self::JS_KERN);
        self::assertMatchesRegularExpression(
            '/function\s+initSidebarScrollHighlight\s*\(/',
            $js,
            'event-task-tree.js muss initSidebarScrollHighlight definieren '
            . '(Phase 2 Sidebar-Scroll-Highlight).'
        );
    }

    public function test_js_defines_initTreeCollapseControls(): void
    {
        $js = $this->read(self::JS_KERN);
        self::assertMatchesRegularExpression(
            '/function\s+initTreeCollapseControls\s*\(/',
            $js,
            'event-task-tree.js muss initTreeCollapseControls definieren '
            . '(Phase 2c Per-Node-Toggle + Expand/Collapse-All).'
        );
    }

    public function test_js_boot_registers_all_init_functions(): void
    {
        $js = $this->read(self::JS_KERN);
        self::assertMatchesRegularExpression(
            '/function\s+boot\s*\(\s*\)/',
            $js,
            'event-task-tree.js muss eine boot()-Funktion haben.'
        );
        // Im boot()-Body muessen alle drei Init-Aufrufe stehen.
        if (!preg_match('/function\s+boot\s*\(\s*\)\s*\{(.*?)\}/s', $js, $m)) {
            self::fail('boot()-Body konnte nicht geparst werden.');
        }
        $bootBody = $m[1];
        foreach ([
            'initTaskTree(',
            'initSidebarScrollHighlight(',
            'initTreeCollapseControls(',
        ] as $call) {
            self::assertStringContainsString(
                $call,
                $bootBody,
                "boot() muss $call aufrufen, damit die Phase-2/2c-"
                . "Erweiterungen im DOMContentLoaded-Handler aktiv werden."
            );
        }
    }

    // =========================================================================
    // Gruppe B — Sidebar-Scroll-Highlight (Phase 2)
    // =========================================================================

    public function test_js_scroll_highlight_uses_data_attribute(): void
    {
        $js = $this->read(self::JS_KERN);
        self::assertStringContainsString(
            'data-sidebar-scroll-target',
            $js,
            'initSidebarScrollHighlight muss auf [data-sidebar-scroll-target] '
            . 'hoeren — das Attribut haengt das Sidebar-Partial pro Leaf an.'
        );
    }

    public function test_js_scroll_highlight_applies_css_class(): void
    {
        $js = $this->read(self::JS_KERN);
        self::assertStringContainsString(
            'task-node--highlighted',
            $js,
            'initSidebarScrollHighlight muss die Klasse task-node--highlighted '
            . 'setzen (CSS-Pulse-Animation aus app.css).'
        );
    }

    public function test_js_scroll_highlight_uses_scrollIntoView(): void
    {
        $js = $this->read(self::JS_KERN);
        self::assertStringContainsString(
            'scrollIntoView',
            $js,
            'initSidebarScrollHighlight muss scrollIntoView() aufrufen, damit '
            . 'der Tree-Knoten in den Viewport rutscht (Browser-nativ).'
        );
    }

    // =========================================================================
    // Gruppe C — Per-Node-Toggle + Expand/Collapse-All (Phase 2c)
    // =========================================================================

    public function test_js_collapse_controls_reacts_to_toggle_node_action(): void
    {
        $js = $this->read(self::JS_KERN);
        self::assertStringContainsString(
            'data-action="toggle-node"',
            $js,
            'initTreeCollapseControls muss auf [data-action="toggle-node"] '
            . 'reagieren (Chevron-Button pro Gruppen-Row).'
        );
    }

    public function test_js_collapse_controls_reacts_to_expand_all(): void
    {
        $js = $this->read(self::JS_KERN);
        self::assertStringContainsString(
            'data-action="expand-all"',
            $js,
            'initTreeCollapseControls muss auf [data-action="expand-all"] '
            . 'reagieren (Toolbar-Button "Alle ausklappen").'
        );
    }

    public function test_js_collapse_controls_reacts_to_collapse_all(): void
    {
        $js = $this->read(self::JS_KERN);
        self::assertStringContainsString(
            'data-action="collapse-all"',
            $js,
            'initTreeCollapseControls muss auf [data-action="collapse-all"] '
            . 'reagieren (Toolbar-Button "Alle einklappen").'
        );
    }

    public function test_js_collapse_toggles_task_node_collapsed_class(): void
    {
        $js = $this->read(self::JS_KERN);
        self::assertStringContainsString(
            'task-node--collapsed',
            $js,
            'initTreeCollapseControls muss die Klasse task-node--collapsed '
            . 'togglen — CSS blendet die Kind-UL darueber aus (non-destruktiv).'
        );
    }

    public function test_js_expand_all_only_targets_group_nodes(): void
    {
        $js = $this->read(self::JS_KERN);
        // Expand/Collapse-All darf nur Gruppen betreffen, sonst versucht es
        // auf Leaf-<li>-Elementen, die Klasse zu setzen — harmlos, aber
        // semantisch falsch.
        self::assertStringContainsString(
            '.task-node--group',
            $js,
            'initTreeCollapseControls muss ueber .task-node--group-Selektor '
            . 'laufen, damit Expand/Collapse-All nur Gruppen beruehren.'
        );
    }
}
