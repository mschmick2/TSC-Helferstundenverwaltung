<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Statische Code-Invarianten fuer Modul 6 I7e-A —
 * OrganizerEventEditController (Phase 1) + showEditor-Action mit
 * Sidebar-Daten-Loading (Phase 2) + Sidebar-Label-Bug-Fix (Phase 2c).
 *
 * Pattern wie EventAdminControllerTreeInvariantsTest: regex/substring
 * gegen File-Inhalt, schnell, kein DB-Bootstrap. Faengt die Fehler-
 * klassen der I7e-A-Phasen:
 *   - Authorization-Slippage: die /organizer-Route-Group hat KEINE
 *     RoleMiddleware, Owner-Check MUSS im Controller liegen.
 *   - Information-Leak: isOrganizer-Check muss VOR findById stehen
 *     (403 vor 404 bei unberechtigtem Zugriff; Pattern aus I7b4).
 *   - Flag-Check (events.tree_editor_enabled) muss VOR isOrganizer
 *     (404 vor 403; Feature-Existenz darf nicht geraten werden).
 *   - actorId-Slippage aus Request-Body (Actor-Spoofing; I7a G4 H3).
 *   - Sidebar-Bug: "Aktive Zusagen" muss auf zusagen_aktiv zugreifen,
 *     nicht auf helpers_total (Phase-2c-Fix: helpers_total ist die
 *     Capacity-Target-Summe, nicht die echten Zusagen).
 *
 * Runtime-Integration (Playwright Spec 15) kommt in Phase 3 Teil 2.
 */
final class OrganizerEventEditControllerInvariantsTest extends TestCase
{
    private const CONTROLLER_PATH =
        __DIR__ . '/../../../src/app/Controllers/OrganizerEventEditController.php';

    /** I7e-B.0.1: Helper wurden in Traits extrahiert. Existenz-Checks
     *  pruefen jetzt gegen diese Dateien statt gegen den Controller-File. */
    private const TRAIT_TREE_ACTION_HELPERS =
        __DIR__ . '/../../../src/app/Controllers/Concerns/TreeActionHelpers.php';
    private const TRAIT_EVENT_TREE_ACTION_HELPERS =
        __DIR__ . '/../../../src/app/Controllers/Concerns/EventTreeActionHelpers.php';

    /** 8 Tree-Actions, die seit Phase 1 existieren. */
    private const TREE_ACTIONS = [
        'showTaskTree',
        'createTaskNode',
        'moveTaskNode',
        'reorderTasks',
        'convertTaskNode',
        'deleteTaskNode',
        'editTaskNode',
        'updateTaskNode',
    ];

    /** Mutating-Actions, die actorId aus Session-User ziehen muessen. */
    private const MUTATING_TREE_ACTIONS = [
        'createTaskNode',
        'moveTaskNode',
        'reorderTasks',
        'convertTaskNode',
        'deleteTaskNode',
        'updateTaskNode',
    ];

    /** Alle Actions inkl. showEditor (Phase 1/2) — insgesamt 9. */
    private const ALL_ACTIONS = [
        'showEditor',
        'showTaskTree',
        'createTaskNode',
        'moveTaskNode',
        'reorderTasks',
        'convertTaskNode',
        'deleteTaskNode',
        'editTaskNode',
        'updateTaskNode',
    ];

    /** Actions, die eine findById-Abfrage machen — hier muss isOrganizer vorher stehen. */
    private const FIND_BY_ID_ACTIONS = [
        'showEditor',
        'showTaskTree',
        'editTaskNode',
    ];

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
    // Gruppe A — Authorization (isOrganizer-Pattern, Information-Leak-Schutz)
    // =========================================================================

    public function test_every_action_calls_isOrganizer(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (self::ALL_ACTIONS as $action) {
            $body = $this->methodBody($code, $action);
            self::assertNotSame('', $body, "OrganizerEventEditController::$action() fehlt.");
            self::assertStringContainsString(
                '->isOrganizer(',
                $body,
                "OrganizerEventEditController::$action() muss EventOrganizerRepository::"
                . "isOrganizer() aufrufen. Die /organizer-Route-Group hat keine "
                . "RoleMiddleware — der Owner-Check MUSS im Controller liegen, sonst "
                . "koennte jeder angemeldete User fremde Events einsehen (Pattern aus I7b4)."
            );
        }
    }

    public function test_isOrganizer_check_precedes_findById(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (self::FIND_BY_ID_ACTIONS as $action) {
            $body = $this->methodBody($code, $action);
            $isOrgPos  = strpos($body, 'isOrganizer(');
            $findIdPos = strpos($body, 'findById(');

            self::assertNotFalse($isOrgPos, "$action() muss isOrganizer() rufen.");
            self::assertNotFalse($findIdPos, "$action() muss findById() rufen.");
            self::assertLessThan(
                $findIdPos,
                $isOrgPos,
                "OrganizerEventEditController::$action(): isOrganizer() muss VOR findById() "
                . "laufen, sonst leakt die Event-Existenz (403 vs. 404-Unterschied; "
                . "Information-Leak-Schutz analog I7b4)."
            );
        }
    }

    public function test_flag_404_check_precedes_isOrganizer(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (self::ALL_ACTIONS as $action) {
            $body = $this->methodBody($code, $action);
            $posFlag = strpos($body, 'treeEditorEnabled');
            $posAuth = strpos($body, 'isOrganizer(');

            self::assertNotFalse(
                $posFlag,
                "OrganizerEventEditController::$action() muss treeEditorEnabled()-Check rufen."
            );
            self::assertNotFalse(
                $posAuth,
                "OrganizerEventEditController::$action() muss isOrganizer()-Check rufen."
            );
            self::assertLessThan(
                $posAuth,
                $posFlag,
                "OrganizerEventEditController::$action(): Flag-Check muss VOR isOrganizer "
                . "laufen, sonst leakt die Feature-Existenz an Unberechtigte "
                . "(404 vs. 403-Unterschied; I7a G4 H2)."
            );
        }
    }

    public function test_no_mutating_action_reads_actorId_from_request(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (self::MUTATING_TREE_ACTIONS as $action) {
            $body = $this->methodBody($code, $action);

            self::assertDoesNotMatchRegularExpression(
                "/\\\$actorId\\s*=\\s*\\(int\\)\\s*\\\$data\\b/",
                $body,
                "OrganizerEventEditController::$action() darf actorId NICHT aus "
                . "\$data lesen (Actor-Spoofing-Schutz H3 aus I7a G4)."
            );
            self::assertDoesNotMatchRegularExpression(
                "/\\\$data\\[['\"]actor_id['\"]\\]/",
                $body,
                "OrganizerEventEditController::$action() darf kein actor_id-Feld "
                . "aus \$data lesen."
            );
            // Positive Gegenprobe: $actorId kommt aus $user->getId().
            self::assertMatchesRegularExpression(
                '/\$actorId\s*=\s*\(int\)\s*\$user->getId\(\)/',
                $body,
                "OrganizerEventEditController::$action() muss actorId aus "
                . "\$user->getId() holen."
            );
        }
    }

    // =========================================================================
    // Gruppe B — showEditor-Sidebar-Daten-Loading (Phase 2)
    // =========================================================================

    public function test_showEditor_loads_tree_data(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        self::assertMatchesRegularExpression(
            '/\$this->treeAggregator->buildTree\s*\(/',
            $body,
            'showEditor() muss treeAggregator->buildTree() aufrufen — der Tree '
            . 'wird Server-seitig vor-aggregiert, damit der erste Render ohne '
            . 'zweiten HTTP-Call auskommt (Phase 2 I7e-A).'
        );
    }

    public function test_showEditor_loads_flat_list_for_sidebar(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        self::assertMatchesRegularExpression(
            '/\$this->treeAggregator->flattenToList\s*\(/',
            $body,
            'showEditor() muss flattenToList() aufrufen — Basis fuer Sidebar-'
            . 'Panel-3 (chronologische Task-Liste) und computeBelegungsSummary '
            . '(Panel-2-Zahlen).'
        );
    }

    public function test_showEditor_loads_organizers_via_listForEvent(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        self::assertStringContainsString(
            'organizerRepo->listForEvent(',
            $body,
            'showEditor() muss organizerRepo->listForEvent() aufrufen — liefert '
            . 'Organisator-Datensaetze fuer Sidebar-Panel-1 (Metadaten).'
        );
    }

    public function test_showEditor_loads_task_categories(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        self::assertStringContainsString(
            'categoryRepo->findAllActive(',
            $body,
            'showEditor() muss categoryRepo->findAllActive() aufrufen — die '
            . 'Kategorien-Liste wird als data-categories-Attribut am Tree-'
            . 'Widget gerendert (JS liest sie beim Modal-Aufbau).'
        );
    }

    public function test_showEditor_calls_computeBelegungsSummary(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        self::assertStringContainsString(
            '$this->computeBelegungsSummary(',
            $body,
            'showEditor() muss computeBelegungsSummary() aufrufen, damit die '
            . 'Sidebar-Panel-2-Zahlen (Zusagen, Soll, Offen, Stunden, Status) '
            . 'gerendert werden.'
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

    public function test_showEditor_renders_organizer_editor_view(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        self::assertStringContainsString(
            "'organizer/events/editor'",
            $body,
            'showEditor() rendert die Organizer-Container-View '
            . '(organizer/events/editor.php), NICHT die Admin-Variante.'
        );
    }

    // =========================================================================
    // Gruppe C — computeBelegungsSummary (Phase-2c-Bug-Fix)
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
            'computeBelegungsSummary() muss einen zusagen_aktiv-Schluessel im '
            . 'Rueckgabe-Array liefern (Phase-2c-Bug-Fix: die Sidebar-Zeile '
            . '"Aktive Zusagen" hatte vorher faelschlich helpers_total angezeigt).'
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

    public function test_computeBelegungsSummary_keeps_helpers_total_for_soll(): void
    {
        $body = $this->methodBody(
            $this->read(self::TRAIT_EVENT_TREE_ACTION_HELPERS),
            'computeBelegungsSummary'
        );
        self::assertMatchesRegularExpression(
            "/'helpers_total'\\s*=>/",
            $body,
            'computeBelegungsSummary() muss weiterhin helpers_total liefern '
            . '(Helfer-Soll-Summe aus capacity_target-Werten).'
        );
    }

    public function test_showEditor_passes_assignmentCounts_to_summary(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'showEditor');
        self::assertMatchesRegularExpression(
            '/computeBelegungsSummary\s*\(\s*\$treeData\s*,\s*\$flatList\s*,\s*\$assignmentCounts\s*\)/',
            $body,
            'showEditor() muss assignmentCounts als dritten Parameter an '
            . 'computeBelegungsSummary() uebergeben — ohne das fehlt die '
            . 'zusagen_aktiv-Zahl in der Sidebar.'
        );
    }

    // =========================================================================
    // Gruppe D — Input-Normalisierung (Typ-Drift-Audit, Spiegel EventAdmin)
    // =========================================================================

    public function test_createTaskNode_normalizes_form_inputs(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'createTaskNode');
        self::assertStringContainsString(
            'normalizeTreeFormInputs',
            $body,
            'createTaskNode() muss $data ueber normalizeTreeFormInputs() fuehren, '
            . 'sonst droht parent_task_id-TypeError (Duplikat-Pattern aus I7b1).'
        );
    }

    public function test_updateTaskNode_normalizes_form_inputs(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'updateTaskNode');
        self::assertStringContainsString(
            'normalizeTreeFormInputs',
            $body,
            'updateTaskNode() muss $data ueber normalizeTreeFormInputs() fuehren '
            . '(leere Strings in Shape-Feldern als null).'
        );
    }

    public function test_convertTaskNode_normalizes_form_inputs(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'convertTaskNode');
        self::assertStringContainsString(
            'normalizeTreeFormInputs',
            $body,
            'convertTaskNode() muss $data ueber normalizeTreeFormInputs() fuehren, '
            . 'weil target=leaf die Leaf-Shape-Felder durchreicht.'
        );
    }

    public function test_moveTaskNode_casts_new_parent_id_to_nullable_int(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'moveTaskNode');
        self::assertMatchesRegularExpression(
            "/new_parent_id.*?\\(int\\)\\s*\\\$data\\['new_parent_id'\\]/s",
            $body,
            'moveTaskNode() muss new_parent_id explizit zu ?int casten, sonst '
            . 'scheitert TaskTreeService::move() an strict_types.'
        );
    }

    public function test_reorderTasks_casts_ordered_ids_to_int_array(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'reorderTasks');
        self::assertStringContainsString(
            "array_map('intval'",
            $body,
            'reorderTasks() muss ordered_task_ids via array_map intval casten '
            . '(Service reorderSiblings erwartet int[]).'
        );
    }

    // =========================================================================
    // Gruppe E — Service-Dispatch (Spiegel EventAdmin)
    // =========================================================================

    public function test_convertTaskNode_dispatches_on_target(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'convertTaskNode');
        self::assertMatchesRegularExpression(
            "/'group'\\s*=>\\s*\\\$this->treeService->convertToGroup\\(/",
            $body,
            "convertTaskNode() muss target='group' auf convertToGroup() mappen."
        );
        self::assertMatchesRegularExpression(
            "/'leaf'\\s*=>\\s*\\\$this->treeService->convertToLeaf\\(/",
            $body,
            "convertTaskNode() muss target='leaf' auf convertToLeaf() mappen."
        );
    }

    public function test_reorderTasks_calls_reorderSiblings(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'reorderTasks');
        self::assertStringContainsString(
            'treeService->reorderSiblings(',
            $body,
            'reorderTasks() muss reorderSiblings() aufrufen (nicht reorder()).'
        );
    }

    // =========================================================================
    // Gruppe F — Redirect-Target (Organizer-spezifisch)
    // =========================================================================

    public function test_success_response_redirects_to_organizer_editor(): void
    {
        // I7e-B.0.1: treeSuccessResponse liegt jetzt im Trait und ist
        // kontext-neutral. Der Redirect-Path wird an der Aufruf-Stelle
        // gesetzt — dort pruefen wir, dass /organizer/events/{id}/editor
        // geliefert wird, NICHT /admin/events.
        $code = $this->read(self::CONTROLLER_PATH);
        self::assertStringContainsString(
            "treeSuccessResponse(\$request, \$response, '/organizer/events/' . \$eventId . '/editor',",
            $code,
            "OrganizerEventEditController muss treeSuccessResponse mit "
            . "/organizer/events/{id}/editor als Redirect-Path aufrufen. "
            . "Ein versehentlicher Aufruf mit /admin/events/... wuerde den "
            . "Organizer auf eine Admin-Route schicken, wo er 403 bekommt."
        );
        self::assertStringNotContainsString(
            "treeSuccessResponse(\$request, \$response, '/admin/",
            $code,
            "OrganizerEventEditController darf NICHT mit /admin-Pfad redirecten."
        );
    }

    public function test_error_response_redirects_to_organizer_editor(): void
    {
        // Analog zu treeSuccessResponse, siehe dort.
        $code = $this->read(self::CONTROLLER_PATH);
        self::assertStringContainsString(
            "treeErrorResponse(\$request, \$response, '/organizer/events/' . \$eventId . '/editor',",
            $code,
            "OrganizerEventEditController muss treeErrorResponse mit "
            . "/organizer/events/{id}/editor aufrufen, nicht mit /admin-Pfad."
        );
    }

    // =========================================================================
    // Gruppe G — JSON-Serialization + Edit-Response
    // =========================================================================

    public function test_showTaskTree_serializes_tree_with_serializeTreeForJson(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'showTaskTree');
        self::assertStringContainsString(
            'serializeTreeForJson',
            $body,
            'showTaskTree() muss den Aggregator-Output ueber serializeTreeForJson() '
            . 'flachklopfen — sonst kommt EventTask-Objekt als leeres JSON zurueck.'
        );
        // I7e-B.0.1: serializeTreeForJson liegt im Trait EventTreeActionHelpers.
        self::assertNotSame(
            '',
            $this->methodBody($this->read(self::TRAIT_EVENT_TREE_ACTION_HELPERS), 'serializeTreeForJson'),
            'Helper serializeTreeForJson() muss im EventTreeActionHelpers-Trait existieren.'
        );
    }

    public function test_editTaskNode_returns_ancestor_path_as_string(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'editTaskNode');
        self::assertMatchesRegularExpression(
            "/implode\\(\\s*' > '\\s*,/",
            $body,
            "editTaskNode() muss ancestor_path via implode(' > ', ...) zu einem "
            . "String formen — das JS macht split(' > ') darauf."
        );
        self::assertStringContainsString(
            "'ancestor_path' => \$ancestorPath",
            $body,
            'editTaskNode() muss ancestor_path als Top-Level-Key in der JSON-Response liefern.'
        );
    }

    // =========================================================================
    // Gruppe H — Audit via Service-Delegation
    // =========================================================================

    public function test_no_tree_action_calls_auditService_directly(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (self::TREE_ACTIONS as $action) {
            $body = $this->methodBody($code, $action);
            self::assertStringNotContainsString(
                'auditService->log(',
                $body,
                "OrganizerEventEditController::$action() darf auditService NICHT "
                . "direkt aufrufen. Audit laeuft ausschliesslich ueber TaskTreeService."
            );
        }
    }

    // =========================================================================
    // Gruppe I — Konstruktor-Dependencies
    // =========================================================================

    public function test_constructor_has_required_dependencies(): void
    {
        // Konstruktor nutzt PHP-8-Promoted-Parameter — die Dependencies stehen
        // IN der Signatur, nicht im Body. methodBody() greift den Body, der
        // bei Promoted-Ctors leer ist. Darum pruefen wir gegen den gesamten
        // File-Inhalt (Promoted-Property-Pattern taucht nur im Ctor auf).
        $code = $this->read(self::CONTROLLER_PATH);

        $expected = [
            'EventRepository',
            'EventTaskRepository',
            'EventTaskAssignmentRepository',
            'EventOrganizerRepository',
            'TaskTreeService',
            'TaskTreeAggregator',
            'CategoryRepository',
            'SettingsService',
        ];
        foreach ($expected as $dep) {
            self::assertMatchesRegularExpression(
                '/private\s+' . preg_quote($dep, '/') . '\s+\$/',
                $code,
                "Konstruktor muss $dep als Promoted-Property injecten. "
                . "CategoryRepository wurde in Phase 2 ergaenzt (Sidebar "
                . "benoetigt Kategorien fuer das data-categories-Attribut)."
            );
        }
    }

    // =========================================================================
    // Gruppe J — IDOR-Schutz (G4 Dim 3, Security-Fix)
    // =========================================================================

    public function test_mutating_actions_check_task_belongs_to_event(): void
    {
        // I7e-B.0.1: der IDOR-Scope-Check ist in die Trait-Methode
        // assertTaskBelongsToEvent extrahiert. Der Action-Body muss sie
        // aufrufen; die eigentliche Pruef-Logik liegt im Trait und wird
        // dort durch eine eigene Invariante abgedeckt.
        $code = $this->read(self::CONTROLLER_PATH);
        $actionsNeedingScopeCheck = [
            'moveTaskNode',
            'convertTaskNode',
            'deleteTaskNode',
            'updateTaskNode',
        ];
        foreach ($actionsNeedingScopeCheck as $action) {
            $body = $this->methodBody($code, $action);
            self::assertNotSame('', $body, "Action $action() fehlt.");
            self::assertMatchesRegularExpression(
                '/\$this->assertTaskBelongsToEvent\(/',
                $body,
                "OrganizerEventEditController::$action() muss assertTaskBelongsToEvent "
                . "aufrufen (IDOR-Schutz via EventTreeActionHelpers-Trait, G4 Dim 3)."
            );
        }

        // Die eigentliche Scope-Logik inkl. 404-Response wird im Trait
        // gepruefft.
        $traitBody = $this->methodBody(
            $this->read(self::TRAIT_EVENT_TREE_ACTION_HELPERS),
            'assertTaskBelongsToEvent'
        );
        self::assertMatchesRegularExpression(
            '/\$task->getEventId\(\)\s*!==\s*\$eventId/',
            $traitBody,
            'EventTreeActionHelpers::assertTaskBelongsToEvent() muss den '
            . 'Event-Scope-Vergleich durchfuehren.'
        );
        self::assertMatchesRegularExpression(
            '/->withStatus\(\s*404\s*\)/',
            $traitBody,
            'assertTaskBelongsToEvent() muss bei Miss 404 liefern (nicht 403 — '
            . 'Task-Existenz im fremden Event soll nicht geraten werden).'
        );
    }

    public function test_scope_check_follows_isOrganizer_precedes_service_call(): void
    {
        // I7e-B.0.1: Scope-Check-Marker ist jetzt der Trait-Aufruf.
        $code = $this->read(self::CONTROLLER_PATH);
        $actions = ['moveTaskNode', 'convertTaskNode', 'deleteTaskNode', 'updateTaskNode'];
        foreach ($actions as $action) {
            $body = $this->methodBody($code, $action);

            $posIsOrg     = strpos($body, 'isOrganizer(');
            $posScopeCheck = strpos($body, 'assertTaskBelongsToEvent(');
            $posService   = strpos($body, '$this->treeService->');

            self::assertNotFalse($posIsOrg, "$action: isOrganizer-Check fehlt.");
            self::assertNotFalse($posScopeCheck, "$action: Scope-Check fehlt.");
            self::assertNotFalse($posService, "$action: Service-Call fehlt.");

            self::assertLessThan(
                $posScopeCheck,
                $posIsOrg,
                "$action: isOrganizer MUSS vor dem Scope-Check laufen "
                . "(403 vor 404 bei fehlender Organizer-Rolle)."
            );
            self::assertLessThan(
                $posService,
                $posScopeCheck,
                "$action: Scope-Check MUSS vor dem Service-Call laufen, "
                . "sonst operiert der Service auf einer fremden Task-ID."
            );
        }
    }
}
