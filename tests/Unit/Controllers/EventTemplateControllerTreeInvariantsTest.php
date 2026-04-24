<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Statische Code-Invarianten fuer EventTemplateController Tree-Actions,
 * die beiden generalisierten Partials _task_tree_node.php und
 * _task_tree_readonly.php, event-task-tree.js-Kontext-Awareness und
 * ViewHelper::formatOffsetMinutes (Modul 6 I7c).
 *
 * Pattern wie EventAdminControllerTreeInvariantsTest: Regex/Substring-
 * Checks gegen den File-Inhalt. Ziel: Regressionen in der $context-Flag-
 * Generalisierung und im Drei-Mode-Switch schnell erkennen, bevor die
 * Laufzeit sie freilegt.
 */
final class EventTemplateControllerTreeInvariantsTest extends TestCase
{
    private const CONTROLLER_PATH = __DIR__ . '/../../../src/app/Controllers/EventTemplateController.php';
    private const PARTIAL_EDITOR  = __DIR__ . '/../../../src/app/Views/admin/events/_task_tree_node.php';
    private const PARTIAL_RO      = __DIR__ . '/../../../src/app/Views/admin/events/_task_tree_readonly.php';
    private const VIEW_EDIT       = __DIR__ . '/../../../src/app/Views/admin/event-templates/edit.php';
    private const VIEW_SHOW       = __DIR__ . '/../../../src/app/Views/admin/event-templates/show.php';
    private const JS_KERN         = __DIR__ . '/../../../src/public/js/event-task-tree.js';

    /**
     * Tree-Actions als eigenstaendige Public-API des Controllers.
     */
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
    // Gruppe A — Controller Tree-Actions
    // =========================================================================

    public function test_all_tree_actions_present(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (self::TREE_ACTIONS as $a) {
            self::assertMatchesRegularExpression(
                '/public function ' . $a . '\s*\(/',
                $code,
                "EventTemplateController::{$a}() muss existieren (I7c Phase 1)."
            );
        }
    }

    public function test_all_tree_actions_gate_on_flag(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (self::TREE_ACTIONS as $a) {
            $body = $this->methodBody($code, $a);
            self::assertMatchesRegularExpression(
                '/!\s*\$this->treeEditorEnabled\(\)/',
                $body,
                "{$a}: muss Flag pruefen und bei 0 mit 404 antworten "
                . "(Information-Leak-Schutz, konsistent zu I7b1)."
            );
        }
    }

    public function test_mutating_tree_actions_respond_with_error_envelope(): void
    {
        // createTaskNode/updateTaskNode/moveTaskNode/... rufen
        // treeErrorResponse() bei ValidationException/BusinessRuleException.
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (
            [
                'createTaskNode',
                'moveTaskNode',
                'reorderTasks',
                'convertTaskNode',
                'deleteTaskNode',
                'updateTaskNode',
            ] as $a
        ) {
            $body = $this->methodBody($code, $a);
            self::assertStringContainsString(
                'treeErrorResponse',
                $body,
                "{$a}: Fehler-Pfad muss treeErrorResponse() nutzen."
            );
        }
    }

    public function test_convertTaskNode_dispatches_on_target(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'convertTaskNode');

        self::assertMatchesRegularExpression(
            "/target\s*===\s*'group'/",
            $body,
            'convertTaskNode muss target=group -> convertToGroup dispatchen.'
        );
        self::assertMatchesRegularExpression(
            "/target\s*===\s*'leaf'/",
            $body,
            'convertTaskNode muss target=leaf -> convertToLeaf dispatchen.'
        );
    }

    public function test_normalizeTemplateTreeFormInputs_casts_parent_id(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'normalizeTemplateTreeFormInputs');

        // Regressions-Schutz gegen HTTP-Typ-Drift analog zum Event-Commit
        // e142d9d: parent_template_task_id kommt als String aus dem Hidden-
        // Input, der Service verlangt ?int.
        self::assertStringContainsString(
            "'parent_template_task_id'",
            $body,
            'normalizeTemplateTreeFormInputs muss parent_template_task_id '
            . 'explizit behandeln.'
        );
        self::assertMatchesRegularExpression(
            '/===\s*\'\'/',
            $body,
            'Leerstring-Behandlung muss vorhanden sein ("" -> null).'
        );
    }

    public function test_normalizeTemplateTreeFormInputs_emptystring_to_null_offsets(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'normalizeTemplateTreeFormInputs');

        // Analog zum Event-Commit d7ff41c fuer start_at/end_at: leere
        // Strings zu null, damit die Service-Validation greift.
        self::assertStringContainsString(
            'default_offset_minutes_start',
            $body,
            'normalizeTemplateTreeFormInputs muss default_offset_minutes_start '
            . 'auf leer->null normalisieren.'
        );
        self::assertStringContainsString(
            'default_offset_minutes_end',
            $body,
            'normalizeTemplateTreeFormInputs muss default_offset_minutes_end '
            . 'auf leer->null normalisieren.'
        );
    }

    // =========================================================================
    // Gruppe B — edit() Drei-Mode-Logik (Phase 2 + 2b)
    // =========================================================================

    public function test_edit_sets_treeMode_variable(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'edit');

        self::assertMatchesRegularExpression(
            "/\\\$treeMode\s*=\s*'legacy'/",
            $body,
            'edit() muss $treeMode initial auf "legacy" setzen.'
        );
        self::assertMatchesRegularExpression(
            "/\\\$treeMode\s*=\s*\\\$isLocked\s*\?\s*'readonly'\s*:\s*'editor'/",
            $body,
            'edit() muss $treeMode je nach Lock-Status auf "readonly" oder '
            . '"editor" setzen (Drei-Mode aus Phase 2b).'
        );
    }

    public function test_edit_locked_condition_uses_isCurrent_and_hasDerivedEvents(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'edit');

        self::assertMatchesRegularExpression(
            '/\$hasDerivedEvents/',
            $body,
            'edit() muss hasDerivedEvents in die Lock-Bedingung einbeziehen.'
        );
        self::assertMatchesRegularExpression(
            '/!\s*\$template->isCurrent\(\)/',
            $body,
            'edit() muss !isCurrent() in die Lock-Bedingung einbeziehen.'
        );
    }

    public function test_edit_passes_treeMode_and_data_to_view(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'edit');

        foreach (['treeMode', 'treeData', 'treeEditorEnabled', 'csrfToken'] as $key) {
            self::assertMatchesRegularExpression(
                "/'" . $key . "'\s*=>/",
                $body,
                "edit() muss '{$key}' an die View uebergeben."
            );
        }
    }

    // =========================================================================
    // Gruppe C — show() Read-Preview (Phase 3)
    // =========================================================================

    public function test_show_loads_tree_preview_data_conditionally(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'show');

        self::assertMatchesRegularExpression(
            '/\$treePreviewData\s*=\s*null/',
            $body,
            'show() muss $treePreviewData default auf null setzen.'
        );
        self::assertStringContainsString(
            '$this->treeEditorEnabled()',
            $body,
            'show() muss Flag pruefen, bevor Tree-Daten geladen werden.'
        );
        self::assertStringContainsString(
            '$this->hasTreeStructure(',
            $body,
            'show() muss hasTreeStructure() pruefen — flache Templates '
            . 'behalten die flache Liste.'
        );
        self::assertStringContainsString(
            '$this->treeAggregator->buildTree(',
            $body,
            'show() muss den Aggregator fuer den Read-Preview aufrufen.'
        );
    }

    public function test_hasTreeStructure_helper_exists(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'hasTreeStructure');

        self::assertNotSame(
            '',
            $body,
            'hasTreeStructure()-Helfer muss existieren.'
        );
        self::assertStringContainsString(
            '->isGroup()',
            $body,
            'hasTreeStructure muss isGroup() abfragen.'
        );
        self::assertStringContainsString(
            '->getParentTemplateTaskId()',
            $body,
            'hasTreeStructure muss getParentTemplateTaskId() abfragen.'
        );
    }

    // =========================================================================
    // Gruppe D — Partials $context + $entityId
    // =========================================================================

    public function test_task_tree_node_partial_supports_context_flag(): void
    {
        $partial = $this->read(self::PARTIAL_EDITOR);

        self::assertMatchesRegularExpression(
            '/\$context\s*=\s*\$context\s*\?\?\s*\'event\'/',
            $partial,
            '_task_tree_node.php muss $context mit Default "event" normalisieren.'
        );
        self::assertMatchesRegularExpression(
            '/\$entityId\s*=\s*\$entityId\s*\?\?\s*\(\$eventId\s*\?\?/',
            $partial,
            '_task_tree_node.php muss $entityId mit Fallback auf $eventId normalisieren.'
        );
    }

    public function test_task_tree_node_partial_builds_context_aware_urls(): void
    {
        $partial = $this->read(self::PARTIAL_EDITOR);

        // URL-Prefix-Switch: /admin/events/ vs. /admin/event-templates/
        self::assertMatchesRegularExpression(
            "/\\\$context\s*===\s*'template'/",
            $partial,
            '_task_tree_node.php muss auf Context-"template" switchen.'
        );
        self::assertStringContainsString(
            "'/admin/event-templates/'",
            $partial,
            '_task_tree_node.php muss Template-URL-Prefix enthalten.'
        );
        self::assertStringContainsString(
            "'/admin/events/'",
            $partial,
            '_task_tree_node.php muss Event-URL-Prefix enthalten.'
        );
    }

    public function test_task_tree_readonly_partial_supports_context_flag(): void
    {
        $partial = $this->read(self::PARTIAL_RO);

        self::assertMatchesRegularExpression(
            '/\$context\s*=\s*\$context\s*\?\?\s*\'event\'/',
            $partial,
            '_task_tree_readonly.php muss $context normalisieren.'
        );
        self::assertStringContainsString(
            'formatOffsetMinutes',
            $partial,
            '_task_tree_readonly.php muss im Template-Context '
            . 'ViewHelper::formatOffsetMinutes nutzen.'
        );
        self::assertStringContainsString(
            'formatDateTime',
            $partial,
            '_task_tree_readonly.php muss im Event-Context '
            . 'ViewHelper::formatDateTime nutzen.'
        );
    }

    // =========================================================================
    // Gruppe E — Edit-View Drei-Mode (Phase 2 + 2b)
    // =========================================================================

    public function test_edit_view_switches_on_three_modes(): void
    {
        $view = $this->read(self::VIEW_EDIT);

        self::assertMatchesRegularExpression(
            "/\\\$treeMode\s*===\s*'editor'/",
            $view,
            'edit.php muss einen editor-Branch haben.'
        );
        self::assertMatchesRegularExpression(
            "/\\\$treeMode\s*===\s*'readonly'/",
            $view,
            'edit.php muss einen readonly-Branch haben.'
        );
        self::assertMatchesRegularExpression(
            "/\\\$treeMode\s*===\s*'legacy'/",
            $view,
            'edit.php muss einen legacy-Branch haben.'
        );
    }

    public function test_edit_view_editor_branch_loads_js_and_sortable(): void
    {
        $view = $this->read(self::VIEW_EDIT);

        self::assertStringContainsString(
            'event-task-tree.js',
            $view,
            'edit.php muss event-task-tree.js laden.'
        );
        self::assertMatchesRegularExpression(
            '/Sortable\.min\.js/',
            $view,
            'edit.php muss SortableJS laden (Drag-Drop).'
        );
        self::assertStringContainsString(
            'data-context="template"',
            $view,
            'edit.php muss data-context="template" am Wrapper setzen '
            . '(JS liest den Kontext daraus).'
        );
        self::assertStringContainsString(
            'data-entity-id=',
            $view,
            'edit.php muss data-entity-id setzen (Template-ID fuer den JS).'
        );
    }

    public function test_edit_view_readonly_branch_uses_container_closure(): void
    {
        $view = $this->read(self::VIEW_EDIT);

        // Phase 2b: rekursiver Readonly-Render ueber Container-Closure
        // gegen Scope-Leak (Konvention aus .claude/rules/05-frontend.md).
        self::assertMatchesRegularExpression(
            '/\$renderReadonlyNode\s*=\s*function/',
            $view,
            'edit.php readonly-Branch muss $renderReadonlyNode-Closure definieren.'
        );
        self::assertStringContainsString(
            '_task_tree_readonly.php',
            $view,
            'edit.php readonly-Branch muss _task_tree_readonly.php einbinden.'
        );
    }

    // =========================================================================
    // Gruppe F — Show-View Read-Preview (Phase 3)
    // =========================================================================

    public function test_show_view_branches_on_tree_preview(): void
    {
        $view = $this->read(self::VIEW_SHOW);

        self::assertStringContainsString(
            '$showTreePreview',
            $view,
            'show.php muss $showTreePreview-Boolean setzen.'
        );
        self::assertStringContainsString(
            'event-template-tree-preview',
            $view,
            'show.php muss einen spezifischen CSS-Klassen-Namespace fuer '
            . 'den Read-Preview nutzen.'
        );
        self::assertStringContainsString(
            '_task_tree_readonly.php',
            $view,
            'show.php muss _task_tree_readonly.php im Preview-Branch einbinden.'
        );
    }

    // =========================================================================
    // Gruppe G — JS-Kern Kontext-Awareness
    // =========================================================================

    public function test_js_kern_reads_data_context(): void
    {
        $js = $this->read(self::JS_KERN);

        self::assertStringContainsString(
            'dataset.context',
            $js,
            'event-task-tree.js muss data-context aus dem Wrapper lesen.'
        );
    }

    public function test_js_kern_reads_data_entity_id(): void
    {
        $js = $this->read(self::JS_KERN);

        self::assertStringContainsString(
            'dataset.entityId',
            $js,
            'event-task-tree.js muss data-entity-id aus dem Wrapper lesen '
            . '(mit Fallback auf data-event-id).'
        );
        self::assertStringContainsString(
            'dataset.eventId',
            $js,
            'event-task-tree.js muss data-event-id als Rueckwaerts-Fallback '
            . 'unterstuetzen.'
        );
    }

    public function test_js_kern_switches_time_fields_on_context(): void
    {
        $js = $this->read(self::JS_KERN);

        // Template-Branch: default_offset_minutes_start/end als Number-Input.
        self::assertStringContainsString(
            "'default_offset_minutes_start'",
            $js,
            'event-task-tree.js muss im Template-Branch default_offset_minutes_start '
            . 'als Form-Field rendern.'
        );
        self::assertStringContainsString(
            "'default_offset_minutes_end'",
            $js,
            'event-task-tree.js muss default_offset_minutes_end ebenso rendern.'
        );
        // Event-Branch: start_at/end_at als datetime-local.
        self::assertMatchesRegularExpression(
            "/'start_at',/",
            $js,
            'Event-Branch muss start_at-Field weiter haben.'
        );
    }

    public function test_js_kern_parent_id_field_is_context_aware(): void
    {
        $js = $this->read(self::JS_KERN);

        self::assertStringContainsString(
            "'parent_template_task_id'",
            $js,
            'event-task-tree.js muss parent_template_task_id-Feld kennen '
            . '(Template-Kontext-Form-Name).'
        );
        self::assertMatchesRegularExpression(
            '/function\s+parentIdField\s*\(/',
            $js,
            'event-task-tree.js muss eine parentIdField()-Helfer-Funktion '
            . 'haben, die je Kontext den richtigen Feldnamen liefert.'
        );
    }

    // =========================================================================
    // IDOR-Schutz (G4 Dim 3, Security-Fix)
    // =========================================================================

    public function test_mutating_actions_check_task_belongs_to_template(): void
    {
        // I7e-B.0.1: der IDOR-Scope-Check ist in die Trait-Methode
        // assertTaskBelongsToTemplate extrahiert (TemplateTreeActionHelpers).
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
                '/\$this->assertTaskBelongsToTemplate\(/',
                $body,
                "EventTemplateController::$action() muss assertTaskBelongsToTemplate "
                . "aufrufen (IDOR-Schutz via TemplateTreeActionHelpers-Trait)."
            );
        }

        // Die eigentliche Pruef-Logik liegt im Trait.
        $traitPath = __DIR__ . '/../../../src/app/Controllers/Concerns/TemplateTreeActionHelpers.php';
        $traitBody = $this->methodBody((string) file_get_contents($traitPath), 'assertTaskBelongsToTemplate');
        self::assertMatchesRegularExpression(
            '/\$task->getTemplateId\(\)\s*!==\s*\$templateId/',
            $traitBody,
            'TemplateTreeActionHelpers::assertTaskBelongsToTemplate() muss '
            . 'getTemplateId gegen templateId vergleichen.'
        );
        self::assertMatchesRegularExpression(
            '/->withStatus\(\s*404\s*\)/',
            $traitBody,
            'assertTaskBelongsToTemplate() muss 404 bei Miss liefern.'
        );
    }
}
