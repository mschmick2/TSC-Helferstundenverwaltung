<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

/**
 * Statische Code-Invarianten fuer Modul 6 I7b2 — Mitglieder-Accordion-View.
 *
 * Pattern analog zu EventAdminControllerTreeInvariantsTest aus I7b1 Phase 4:
 * Regex/Substring-Checks gegen File-Inhalt. Scope ist enger, weil I7b2 eine
 * reine Read-View-Erweiterung ist (keine neuen Write-Endpunkte, keine
 * Service-Logik).
 *
 * Abgesichert werden:
 *   - Controller-Daten-Durchreichung (Flag, hasStructure, treeData).
 *   - Partial-Struktur: Container-Closure-Rekursion, XSS-Escape, bedingtes
 *     data-open-count-Attribut, <details>/<summary> fuer Gruppen.
 *   - View-Switch-Integritaet in events/show.php (Phase-1-Platzhalter weg,
 *     flache Karten-Ansicht im else-Zweig unberuehrt).
 *   - JS-Filter: Vanilla, keine Fetch-Calls, classList-Toggle.
 *   - Informationsdichte-Paritaet zur Bestand-Karten-Ansicht (1:1
 *     Architect-Entscheidung G1 I7b2).
 */
final class MemberEventAccordionInvariantsTest extends TestCase
{
    private const CONTROLLER_PATH = __DIR__ . '/../../../src/app/Controllers/MemberEventController.php';
    private const VIEW_SHOW       = __DIR__ . '/../../../src/app/Views/events/show.php';
    private const PARTIAL_ACC     = __DIR__ . '/../../../src/app/Views/events/_task_group_accordion.php';
    private const JS_FILTER       = __DIR__ . '/../../../src/public/js/event-task-tree-filter.js';

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    /**
     * Body einer Methode ab Signatur-Zeile bis zur naechsten Method-Def
     * (oder Klassen-Ende). Kopie aus I7b1-Phase-4-Pattern.
     */
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
    // Gruppe A — Controller-Daten-Durchreichung
    // =========================================================================

    public function test_show_action_passes_treeEditorEnabled_to_view(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'show');
        self::assertMatchesRegularExpression(
            "/'treeEditorEnabled'\\s*=>/",
            $body,
            "MemberEventController::show() muss 'treeEditorEnabled' an die View "
            . "uebergeben (I7b2 Phase 1)."
        );
    }

    public function test_show_action_passes_hasTreeStructure_to_view(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'show');
        self::assertMatchesRegularExpression(
            "/'hasTreeStructure'\\s*=>/",
            $body,
            "MemberEventController::show() muss 'hasTreeStructure' an die View "
            . "uebergeben."
        );
    }

    public function test_show_action_passes_treeData_to_view(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'show');
        self::assertMatchesRegularExpression(
            "/'treeData'\\s*=>/",
            $body,
            "MemberEventController::show() muss 'treeData' an die View uebergeben."
        );
    }

    public function test_show_action_computes_hasTreeStructure_without_extra_query(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'show');

        // $tasks wird ohnehin geladen — der hasTreeStructure-Check muss darueber
        // iterieren, nicht eine separate Query absetzen.
        self::assertStringContainsString(
            '$tasks = $this->taskRepo->findByEvent',
            $body,
            'show() laedt $tasks einmalig per findByEvent.'
        );
        $findByEventCalls = substr_count($body, '$this->taskRepo->findByEvent');
        self::assertSame(
            1,
            $findByEventCalls,
            'show() darf taskRepo->findByEvent() nur einmal aufrufen — '
            . 'hasTreeStructure-Check iteriert ueber das bereits geladene Array.'
        );
        self::assertMatchesRegularExpression(
            '/foreach\s*\(\s*\$tasks\s+as\s+/',
            $body,
            'show() muss ueber $tasks iterieren, um hasTreeStructure zu bestimmen.'
        );
    }

    public function test_show_action_calls_aggregator_only_when_tree_exists(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'show');

        $posGuard = strpos($body, 'if ($hasTreeStructure)');
        $posBuild = strpos($body, 'treeAggregator->buildTree');

        self::assertNotFalse($posBuild, 'show() muss treeAggregator->buildTree() aufrufen.');
        self::assertNotFalse($posGuard, 'show() muss hasTreeStructure-Guard um buildTree legen.');
        self::assertLessThan(
            $posBuild,
            $posGuard,
            'Guard (if ($hasTreeStructure)) muss VOR dem buildTree()-Aufruf stehen.'
        );
    }

    // =========================================================================
    // Gruppe B — Partial-Struktur
    // =========================================================================

    public function test_task_group_accordion_partial_uses_container_closure(): void
    {
        $partial = $this->read(self::PARTIAL_ACC);

        // Kinder-Schleife ruft die Closure, kein self-include.
        self::assertStringContainsString(
            '$renderAccordionNode(',
            $partial,
            '_task_group_accordion.php muss Kinder via $renderAccordionNode-Closure '
            . 'rendern (G9-I7b1-Muster, Scope-Leak vermeiden).'
        );
        self::assertDoesNotMatchRegularExpression(
            "/include\\s+__DIR__\\s*\\.\\s*'[^']*_task_group_accordion\\.php'/",
            $partial,
            '_task_group_accordion.php darf sich NICHT selbst per naked include '
            . 'einbinden.'
        );

        // Container (show.php) liefert die Closure mit use-by-reference.
        $show = $this->read(self::VIEW_SHOW);
        self::assertMatchesRegularExpression(
            '/\$renderAccordionNode\s*=\s*function/s',
            $show,
            'events/show.php muss $renderAccordionNode als Closure definieren.'
        );
        self::assertStringContainsString(
            '&$renderAccordionNode',
            $show,
            'events/show.php muss $renderAccordionNode per use-by-reference '
            . 'einbinden fuer den Self-Call.'
        );
    }

    public function test_task_group_accordion_escapes_title(): void
    {
        $partial = $this->read(self::PARTIAL_ACC);

        // ViewHelper::e() oder htmlspecialchars(...) auf $task->getTitle().
        $titleEscapedDirect = str_contains($partial, 'ViewHelper::e($task->getTitle())');
        $titleEscapedHtml   = (bool) preg_match(
            '/htmlspecialchars\(\s*\$task->getTitle\(\)/',
            $partial
        );
        self::assertTrue(
            $titleEscapedDirect || $titleEscapedHtml,
            '_task_group_accordion.php muss getTitle() durch ViewHelper::e() oder '
            . 'htmlspecialchars() rendern (XSS-Schutz).'
        );
    }

    public function test_task_group_accordion_escapes_description(): void
    {
        $partial = $this->read(self::PARTIAL_ACC);

        // ViewHelper::e($task->getDescription()) oder htmlspecialchars.
        $descEscapedDirect = (bool) preg_match(
            '/ViewHelper::e\(\s*\$task->getDescription\(\)/',
            $partial
        );
        $descEscapedHtml = (bool) preg_match(
            '/htmlspecialchars\(\s*\$task->getDescription\(\)/',
            $partial
        );
        self::assertTrue(
            $descEscapedDirect || $descEscapedHtml,
            '_task_group_accordion.php muss getDescription() durch ViewHelper::e() '
            . 'oder htmlspecialchars() rendern (XSS-Schutz).'
        );
    }

    public function test_task_group_accordion_omits_data_open_count_when_capacity_null(): void
    {
        $partial = $this->read(self::PARTIAL_ACC);

        // Muster: data-open-count-Attribut steht hinter einem if-Guard, der
        // den null-Fall ausschliesst. Im Partial entweder per
        // "if (openCount !== null)" oder "if (capTarget !== null)" vor dem
        // Attribut. Wichtig ist: das Attribut darf NICHT unconditional
        // gerendert werden — unbegrenzte Leaves sollen bei aktivem Filter
        // sichtbar bleiben.
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$openCount\s*!==\s*null\s*\).*?data-open-count/s',
            $partial,
            '_task_group_accordion.php muss das data-open-count-Attribut auf '
            . 'Leaves unter der Bedingung ($openCount !== null) rendern, damit '
            . 'unbegrenzte Leaves (capacity_target=null) kein Attribut bekommen '
            . 'und vom Filter-CSS "[data-open-count=\'0\']" nicht getroffen '
            . 'werden (Architect-Antwort B).'
        );

        // Fuer Gruppen: die gleiche Konvention — Attribut unter openSlotsSubtree-
        // Existenz-Check.
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$openSlotsSubtree\s*!==\s*null\s*\).*?data-open-count/s',
            $partial,
            'Gruppen-Knoten muessen data-open-count ebenfalls bedingt rendern.'
        );
    }

    public function test_task_group_accordion_renders_group_as_details_with_summary(): void
    {
        $partial = $this->read(self::PARTIAL_ACC);

        self::assertStringContainsString(
            '<details',
            $partial,
            '_task_group_accordion.php muss Gruppen als <details> rendern '
            . '(natives Accordion, ohne JS bedienbar).'
        );
        self::assertStringContainsString(
            '<summary',
            $partial,
            '_task_group_accordion.php muss <summary> fuer den Gruppen-Titel '
            . 'rendern.'
        );
    }

    // =========================================================================
    // Gruppe C — View-Switch-Integritaet
    // =========================================================================

    public function test_show_view_renders_accordion_only_when_flag_and_structure(): void
    {
        $show = $this->read(self::VIEW_SHOW);

        // Switch-Variable $showAccordion wird aus treeEditorEnabled UND
        // hasTreeStructure berechnet.
        self::assertMatchesRegularExpression(
            '/\$showAccordion\s*=\s*!empty\(\s*\$treeEditorEnabled\s*\)\s*&&\s*!empty\(\s*\$hasTreeStructure\s*\)/s',
            $show,
            'events/show.php muss $showAccordion aus (treeEditorEnabled && '
            . 'hasTreeStructure) ableiten.'
        );

        // Accordion-Pfad ist unter if ($showAccordion):
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$showAccordion\s*\):/',
            $show,
            'events/show.php muss das Accordion per if ($showAccordion) gaten.'
        );

        // Flache Kartenansicht unberuehrt: bleibt als else-Zweig erhalten —
        // Kennzeichen: das bg-primary-Aufgabe-Badge rendert noch im Code.
        self::assertStringContainsString(
            "<span class=\"badge bg-primary\">Aufgabe</span>",
            $show,
            'events/show.php muss die flache Karten-Ansicht im else-Zweig '
            . 'unveraendert behalten (Kennzeichen: Aufgabe-Badge).'
        );
    }

    public function test_show_view_removes_phase1_info_alert(): void
    {
        $show = $this->read(self::VIEW_SHOW);

        // Der Phase-1-Info-Alert war ein Platzhalter mit dem Text
        // "wird in I7b2 Phase 2 nachgeliefert". Muss nach Phase 2 raus sein.
        self::assertStringNotContainsString(
            'Phase 2 nachgeliefert',
            $show,
            'events/show.php darf den Phase-1-Info-Alert-Platzhalter NICHT mehr '
            . 'enthalten — Phase 2 ersetzt ihn durch das Accordion-Partial.'
        );
    }

    // =========================================================================
    // Gruppe D — JS-Filter
    // =========================================================================

    public function test_filter_js_is_vanilla_no_jquery(): void
    {
        $js = $this->read(self::JS_FILTER);

        // Kein jQuery: weder $(...)-Aufruf noch jQuery(-Aufruf.
        self::assertDoesNotMatchRegularExpression(
            '/\$\(\s*[\'"#.]/',
            $js,
            'event-task-tree-filter.js darf kein jQuery-$-Pattern enthalten '
            . '(Vanilla-JS-Konvention).'
        );
        self::assertDoesNotMatchRegularExpression(
            '/\bjQuery\s*\(/',
            $js,
            'event-task-tree-filter.js darf keinen jQuery()-Aufruf enthalten.'
        );
    }

    public function test_filter_js_toggles_class_on_accordion(): void
    {
        $js = $this->read(self::JS_FILTER);

        // classList.toggle ODER classList.add/remove mit 'filter-open-only'.
        $hasToggle = (bool) preg_match(
            "/classList\\.toggle\\(\\s*'filter-open-only'/",
            $js
        );
        $hasAddRemove = (bool) preg_match(
            "/classList\\.add\\(\\s*'filter-open-only'/",
            $js
        ) && (bool) preg_match(
            "/classList\\.remove\\(\\s*'filter-open-only'/",
            $js
        );
        self::assertTrue(
            $hasToggle || $hasAddRemove,
            'event-task-tree-filter.js muss .filter-open-only per classList '
            . 'toggeln (entweder .toggle() oder .add/.remove).'
        );
    }

    public function test_filter_js_has_no_fetch_calls(): void
    {
        $js = $this->read(self::JS_FILTER);

        self::assertDoesNotMatchRegularExpression(
            '/\bfetch\s*\(/',
            $js,
            'event-task-tree-filter.js darf keine fetch()-Calls enthalten '
            . '(reine clientseitige Toggle-Logik, keine Server-Requests).'
        );
        self::assertDoesNotMatchRegularExpression(
            '/XMLHttpRequest/',
            $js,
            'event-task-tree-filter.js darf kein XMLHttpRequest verwenden.'
        );
    }

    // =========================================================================
    // Gruppe E — Informationsdichte-Paritaet (Architect-Entscheidung G1)
    // =========================================================================

    public function test_accordion_leaf_includes_helpers_count(): void
    {
        $partial = $this->read(self::PARTIAL_ACC);

        // Architect-Entscheidung: Formulierung "X von Y offen" oder bei
        // capacity_target=null "Beliebig viele Helfer".
        self::assertStringContainsString(
            'Beliebig viele Helfer',
            $partial,
            '_task_group_accordion.php muss fuer unbegrenzte Leaves '
            . '"Beliebig viele Helfer" rendern (Bestand-Paritaet).'
        );
        self::assertStringContainsString(
            'offen',
            $partial,
            '_task_group_accordion.php muss den "X von Y offen"-Helfer-Count '
            . 'bei konkretem capacity_target zeigen.'
        );
    }

    public function test_accordion_leaf_includes_hours(): void
    {
        $partial = $this->read(self::PARTIAL_ACC);

        // ViewHelper::formatHours wird auch in der flachen Karten-Ansicht
        // verwendet; 1:1-Paritaet erwartet.
        self::assertStringContainsString(
            'ViewHelper::formatHours($task->getHoursDefault())',
            $partial,
            '_task_group_accordion.php muss Leaf-Stunden via '
            . 'ViewHelper::formatHours rendern (1:1 zur Bestand-Karten-Ansicht).'
        );
    }

    public function test_accordion_leaf_includes_slot_time_when_fix(): void
    {
        $partial = $this->read(self::PARTIAL_ACC);

        // Bestand-Pattern: $task->hasFixedSlot() + ViewHelper::formatDateTime.
        self::assertStringContainsString(
            '$task->hasFixedSlot()',
            $partial,
            '_task_group_accordion.php muss hasFixedSlot() pruefen, bevor '
            . 'Slot-Zeit oder "Freies Zeitfenster" gerendert wird.'
        );
        self::assertStringContainsString(
            'ViewHelper::formatDateTime($task->getStartAt())',
            $partial,
            '_task_group_accordion.php muss fix-Slot-Start via '
            . 'ViewHelper::formatDateTime rendern.'
        );
        self::assertStringContainsString(
            'ViewHelper::formatDateTime($task->getEndAt())',
            $partial,
            '_task_group_accordion.php muss fix-Slot-Ende via '
            . 'ViewHelper::formatDateTime rendern.'
        );
        self::assertStringContainsString(
            'Freies Zeitfenster',
            $partial,
            '_task_group_accordion.php muss bei variablem Slot '
            . '"Freies Zeitfenster" rendern (Bestand-Formulierung).'
        );
    }

    public function test_accordion_leaf_includes_assign_form_partial(): void
    {
        $partial = $this->read(self::PARTIAL_ACC);

        self::assertMatchesRegularExpression(
            "/include\\s+__DIR__\\s*\\.\\s*'\\/_assign_form\\.php'/",
            $partial,
            '_task_group_accordion.php muss _assign_form.php im Leaf-Block '
            . 'einbinden (Uebernehmen-Button, Bestand-Wiederverwendung).'
        );
    }

    public function test_accordion_leaf_preserves_three_way_status_button(): void
    {
        $partial = $this->read(self::PARTIAL_ACC);

        // Dreifach-Condition wie im Bestand:
        //   user_has_assignment → "Bereits zugesagt"
        //   isFull              → "Ausgebucht"
        //   sonst               → _assign_form.php
        self::assertStringContainsString(
            'Bereits zugesagt',
            $partial,
            '_task_group_accordion.php muss den "Bereits zugesagt"-Zustand '
            . 'beibehalten (1:1 zur Bestand-Karten-Ansicht).'
        );
        self::assertStringContainsString(
            'Ausgebucht',
            $partial,
            '_task_group_accordion.php muss den "Ausgebucht"-Zustand '
            . 'beibehalten (CAP_MAXIMUM + voll belegt).'
        );
    }
}
