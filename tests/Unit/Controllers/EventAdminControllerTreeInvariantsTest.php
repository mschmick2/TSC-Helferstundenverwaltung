<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Statische Code-Invarianten fuer Modul 6 I7b1 — EventAdminController,
 * zwei Partials, JS-Kern und die Read-Only-Detail-View (Phase 3c).
 *
 * Pattern wie TaskTreeServiceInvariantsTest: Regex/Substring-Checks gegen
 * den File-Inhalt, schnell, kein DB-Bootstrap. Faengt die Fehlerklassen,
 * die in Phase 3 mehrfach als Runtime-Bugs erschienen sind:
 *   - Authorization-/actorId-Slippage (I7a H2/H3).
 *   - Typ-Drift HTTP-String → Service-strict_types (Phase-3-Fix-Commits
 *     e142d9d, d7ff41c).
 *   - Array-vs-Objekt-Schnittstelle Aggregator ↔ Controller ↔ Partial
 *     (Phase-3-Fix c5f78a2).
 *   - Nested-Sortable-Options-Blind-Fixes (Phase-3-Fix 1a530a6).
 *   - naked-include-Scope-Leak in rekursiven Partials.
 *   - XSS via innerHTML auf User-Freitext.
 *   - Audit-Umgehung durch Controller-Direktzugriff.
 *
 * Runtime-Integration-Tests (echte HTTP → Controller → Service → DB)
 * sind FOLGE-Arbeit, nicht Scope dieser Datei. Eintrag im G9-Follow-up.
 */
final class EventAdminControllerTreeInvariantsTest extends TestCase
{
    private const CONTROLLER_PATH   = __DIR__ . '/../../../src/app/Controllers/EventAdminController.php';
    private const PARTIAL_EDITOR    = __DIR__ . '/../../../src/app/Views/admin/events/_task_tree_node.php';
    private const PARTIAL_READONLY  = __DIR__ . '/../../../src/app/Views/admin/events/_task_tree_readonly.php';
    private const VIEW_EDIT         = __DIR__ . '/../../../src/app/Views/admin/events/edit.php';
    private const VIEW_SHOW         = __DIR__ . '/../../../src/app/Views/admin/events/show.php';
    private const JS_KERN           = __DIR__ . '/../../../src/public/js/event-task-tree.js';

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

    private const MUTATING_TREE_ACTIONS = [
        'createTaskNode',
        'moveTaskNode',
        'reorderTasks',
        'convertTaskNode',
        'deleteTaskNode',
        'updateTaskNode',
    ];

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    /**
     * Body einer Methode ab Signatur-Zeile bis zur naechsten Method-Def
     * (oder Klassen-Ende). Kopie aus TaskTreeServiceInvariantsTest.
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
    // Gruppe A — Authorization (I7a H2/H3)
    // =========================================================================

    public function test_each_tree_action_calls_assertEventEditPermission(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (self::TREE_ACTIONS as $action) {
            $body = $this->methodBody($code, $action);
            self::assertNotSame('', $body, "EventAdminController::$action() fehlt.");
            self::assertStringContainsString(
                'assertEventEditPermission',
                $body,
                "EventAdminController::$action() muss assertEventEditPermission() aufrufen "
                . "(IDOR-Schutz H2 aus I7a G4)."
            );
        }
    }

    public function test_no_tree_action_reads_actorId_from_request(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (self::MUTATING_TREE_ACTIONS as $action) {
            $body = $this->methodBody($code, $action);
            // actorId muss aus dem Session-User kommen, nicht aus $data.
            // Pattern wie $data['actor_id'] oder $actorId = (int) $data[...]
            // ist Alarm.
            self::assertDoesNotMatchRegularExpression(
                "/\\\$actorId\\s*=\\s*\\(int\\)\\s*\\\$data\\b/",
                $body,
                "EventAdminController::$action() darf actorId NICHT aus Request-Body lesen "
                . "(Actor-Spoofing-Schutz H3 aus I7a G4)."
            );
            self::assertDoesNotMatchRegularExpression(
                "/\\\$data\\[['\"]actor_id['\"]\\]/",
                $body,
                "EventAdminController::$action() darf kein actor_id-Feld aus \$data lesen."
            );
            // Positive Gegenprobe: $actorId kommt aus $user->getId().
            self::assertMatchesRegularExpression(
                '/\$actorId\s*=\s*\(int\)\s*\$user->getId\(\)/',
                $body,
                "EventAdminController::$action() muss actorId aus \$user->getId() holen."
            );
        }
    }

    public function test_flag_404_check_precedes_authorization(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (self::TREE_ACTIONS as $action) {
            $body = $this->methodBody($code, $action);
            $posFlag = strpos($body, 'treeEditorEnabled');
            $posAuth = strpos($body, 'assertEventEditPermission');
            self::assertNotFalse($posFlag,
                "EventAdminController::$action() muss treeEditorEnabled()-Check rufen.");
            self::assertNotFalse($posAuth,
                "EventAdminController::$action() muss assertEventEditPermission() rufen.");
            self::assertLessThan(
                $posAuth,
                $posFlag,
                "EventAdminController::$action(): Flag-Check muss VOR Authorization laufen, "
                . "sonst leakt die Feature-Existenz an Unberechtigte (404 vs. 403-Unterschied)."
            );
        }
    }

    // =========================================================================
    // Gruppe B — Input-Normalisierung (Typ-Drift-Audit)
    // =========================================================================

    public function test_createTaskNode_normalizes_form_inputs(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'createTaskNode');
        self::assertStringContainsString(
            'normalizeTreeFormInputs',
            $body,
            'createTaskNode() muss $data ueber normalizeTreeFormInputs() fuehren, '
            . 'sonst droht parent_task_id-TypeError (Fix e142d9d).'
        );
    }

    public function test_updateTaskNode_normalizes_form_inputs(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'updateTaskNode');
        self::assertStringContainsString(
            'normalizeTreeFormInputs',
            $body,
            'updateTaskNode() muss $data ueber normalizeTreeFormInputs() fuehren '
            . '(leere Strings in Shape-Feldern als null, Fix d7ff41c).'
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
        // new_parent_id muss vor Service-Aufruf zu ?int gecastet werden.
        // Erwartetes Pattern: explizite Pruefung auf leer/null, sonst (int).
        self::assertMatchesRegularExpression(
            "/new_parent_id.*?\\(int\\)\\s*\\\$data\\['new_parent_id'\\]/s",
            $body,
            'moveTaskNode() muss new_parent_id explizit zu ?int casten, '
            . 'sonst scheitert TaskTreeService::move() an strict_types.'
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

    public function test_normalizer_helper_exists_and_handles_parent_and_empty_strings(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'normalizeTreeFormInputs');
        self::assertNotSame('', $body, 'Helper normalizeTreeFormInputs() fehlt.');
        // parent_task_id-Cast
        self::assertStringContainsString(
            "'parent_task_id'",
            $body,
            'Helper muss parent_task_id normalisieren.'
        );
        // Leere-String-zu-null fuer Shape-Felder
        foreach (['start_at', 'end_at', 'category_id', 'capacity_target'] as $field) {
            self::assertStringContainsString(
                "'$field'",
                $body,
                "Helper muss $field bei leerem String zu null normalisieren."
            );
        }
    }

    // =========================================================================
    // Gruppe C — Service-Dispatch-Korrektheit
    // =========================================================================

    public function test_convertTaskNode_dispatches_on_target_group_to_convertToGroup(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'convertTaskNode');
        self::assertMatchesRegularExpression(
            "/'group'\\s*=>\\s*\\\$this->treeService->convertToGroup\\(/",
            $body,
            "convertTaskNode() muss target='group' auf convertToGroup() mappen "
            . "(Naming aus Phase 1b bestaetigt)."
        );
    }

    public function test_convertTaskNode_dispatches_on_target_leaf_to_convertToLeaf(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'convertTaskNode');
        self::assertMatchesRegularExpression(
            "/'leaf'\\s*=>\\s*\\\$this->treeService->convertToLeaf\\(/",
            $body,
            "convertTaskNode() muss target='leaf' auf convertToLeaf() mappen."
        );
    }

    public function test_reorderTasks_calls_reorderSiblings_not_reorder(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'reorderTasks');
        self::assertStringContainsString(
            'treeService->reorderSiblings(',
            $body,
            'reorderTasks() muss reorderSiblings() aufrufen, nicht reorder() '
            . '(Phase-2-Naming-Diskrepanz dokumentiert).'
        );
        self::assertDoesNotMatchRegularExpression(
            '/treeService->reorder\(/',
            $body,
            'reorderTasks() darf NICHT treeService->reorder() aufrufen — '
            . 'solche Methode existiert nicht, nur reorderSiblings.'
        );
    }

    // =========================================================================
    // Gruppe D — JSON-Serialization
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
        // Helper muss existieren
        self::assertNotSame(
            '',
            $this->methodBody($code, 'serializeTreeForJson'),
            'Private Helper serializeTreeForJson() fehlt.'
        );
    }

    public function test_editTaskNode_returns_ancestor_path_as_string(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'editTaskNode');
        // ancestor_path muss per implode(' > ', ...) zu String werden;
        // Rueckgabe als Array bricht das Frontend (split(' > ') im JS).
        self::assertMatchesRegularExpression(
            "/implode\\(\\s*' > '\\s*,/",
            $body,
            "editTaskNode() muss ancestor_path via implode(' > ', ...) zu einem String "
            . "formen — das JS macht split(' > ') darauf."
        );
        self::assertStringContainsString(
            "'ancestor_path' => \$ancestorPath",
            $body,
            'editTaskNode() muss ancestor_path als Top-Level-Key in der JSON-Response liefern.'
        );
    }

    public function test_edit_view_provides_categories_via_data_attribute(): void
    {
        // Kategorien kommen nicht aus editTaskNode-JSON, sondern aus dem
        // data-categories-Attribut am Tree-Widget. Das JS liest es beim Init.
        $view = $this->read(self::VIEW_EDIT);
        self::assertStringContainsString(
            'data-categories',
            $view,
            'admin/events/edit.php muss data-categories am Tree-Widget rendern, '
            . 'damit das Modal-JS die Kategorien-Liste beim Aufbau hat.'
        );
    }

    // =========================================================================
    // Gruppe E — Partial-Rekursion + XSS-Schutz
    // =========================================================================

    public function test_task_tree_node_partial_uses_container_closure(): void
    {
        // Das Partial selbst ruft die Closure auf ($renderTaskNode), nicht
        // sich selbst per nacktem include — sonst Scope-Leak im foreach.
        $partial = $this->read(self::PARTIAL_EDITOR);
        self::assertStringContainsString(
            '$renderTaskNode(',
            $partial,
            '_task_tree_node.php muss Kinder via $renderTaskNode-Closure rendern, '
            . 'nicht per naked include (Scope-Leak-Pattern).'
        );
        self::assertDoesNotMatchRegularExpression(
            "/include\\s+__DIR__\\s*\\.\\s*'[^']*_task_tree_node\\.php'/",
            $partial,
            '_task_tree_node.php darf sich NICHT selbst per naked include einbinden.'
        );
        // Container (edit.php) liefert die Closure.
        $edit = $this->read(self::VIEW_EDIT);
        self::assertMatchesRegularExpression(
            '/\$renderTaskNode\s*=\s*function/s',
            $edit,
            'admin/events/edit.php muss $renderTaskNode als Closure definieren '
            . '(mit use-by-reference fuer Self-Call).'
        );
    }

    public function test_task_tree_readonly_partial_uses_container_closure(): void
    {
        $partial = $this->read(self::PARTIAL_READONLY);
        self::assertStringContainsString(
            '$renderReadonlyNode(',
            $partial,
            '_task_tree_readonly.php muss Kinder via $renderReadonlyNode-Closure rendern.'
        );
        self::assertDoesNotMatchRegularExpression(
            "/include\\s+__DIR__\\s*\\.\\s*'[^']*_task_tree_readonly\\.php'/",
            $partial,
            '_task_tree_readonly.php darf sich NICHT selbst per naked include einbinden.'
        );
        $show = $this->read(self::VIEW_SHOW);
        self::assertMatchesRegularExpression(
            '/\$renderReadonlyNode\s*=\s*function/s',
            $show,
            'admin/events/show.php muss $renderReadonlyNode als Closure definieren.'
        );
    }

    public function test_partials_escape_user_text_fields(): void
    {
        $editor   = $this->read(self::PARTIAL_EDITOR);
        $readonly = $this->read(self::PARTIAL_READONLY);

        // Beide Partials muessen User-Text (title, description) durch
        // ViewHelper::e() schicken — entweder direkt auf $task->getX() ODER
        // ueber eine lokale $title/$description-Variable, die aus dem
        // Task-Objekt gefuellt wird. Ungeschuetztes echo des Title-
        // Ausdrucks ist XSS-Risiko.
        foreach ([
            self::PARTIAL_EDITOR   => $editor,
            self::PARTIAL_READONLY => $readonly,
        ] as $file => $content) {
            $short = basename($file);

            // Muss ViewHelper::e() verwenden.
            self::assertStringContainsString(
                'ViewHelper::e(',
                $content,
                "$short muss ViewHelper::e() fuer User-Text verwenden."
            );

            // Title: entweder direkt escaped oder via lokale $title-Var, die
            // aus $task->getTitle() gefuellt ist.
            $titleDirect = str_contains($content, 'ViewHelper::e($task->getTitle())');
            $titleLocal  = str_contains($content, '$title') && str_contains($content, '$task->getTitle()')
                && (bool) preg_match('/\$title\s*=[^;]*\$task->getTitle\(\)/', $content)
                && (bool) preg_match('/ViewHelper::e\(\s*\$title\s*\)/', $content);
            self::assertTrue(
                $titleDirect || $titleLocal,
                "$short muss getTitle() durch ViewHelper::e() rendern "
                . "(direkt oder ueber lokale \$title-Var)."
            );

            // Description analog.
            $descDirect = (bool) preg_match(
                '/ViewHelper::e\(\s*\$task->getDescription\(\)/',
                $content
            );
            $descLocal  = str_contains($content, '$description')
                && (bool) preg_match('/\$description\s*=[^;]*\$task->getDescription\(\)/', $content)
                && (bool) preg_match('/ViewHelper::e\(\s*\$description\s*\)/', $content);
            self::assertTrue(
                $descDirect || $descLocal,
                "$short muss getDescription() durch ViewHelper::e() rendern "
                . "(direkt oder ueber lokale \$description-Var)."
            );

            // Negativ-Gegenprobe: keine rohen Echo-Ausdruecke von
            // $task->getTitle() oder $task->getDescription() ohne
            // ViewHelper::e()-Umhuellung.
            self::assertDoesNotMatchRegularExpression(
                '/<\?=\s*\$task->getTitle\(\)\s*\?>/',
                $content,
                "$short darf getTitle() NICHT ungeschuetzt echo-en."
            );
        }
    }

    public function test_no_raw_innerHTML_on_user_text_in_js(): void
    {
        $js = $this->read(self::JS_KERN);

        // Jede Zuweisung $...innerHTML = ... muss entweder mit leerem String
        // oder mit einem statischen HTML-Fragment erfolgen, nie mit einer
        // User-Text-Variable direkt. Heuristischer Check: innerHTML = ...title
        // oder innerHTML = ...description faellt durch.
        self::assertDoesNotMatchRegularExpression(
            '/innerHTML\s*=\s*[^;]*(task\.title|task\.description|ancestor_path\s*\+)/',
            $js,
            'event-task-tree.js darf title/description/ancestor_path NICHT per innerHTML '
            . 'zuweisen — XSS-Risiko. textContent oder escapeHtml verwenden.'
        );

        // Positive Gegenprobe: textContent wird verwendet.
        self::assertStringContainsString(
            'textContent',
            $js,
            'event-task-tree.js muss textContent fuer User-Text-Einsetzung verwenden.'
        );
    }

    // =========================================================================
    // Gruppe F — Audit via Service-Delegation
    // =========================================================================

    public function test_no_tree_action_calls_auditService_directly(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (self::TREE_ACTIONS as $action) {
            $body = $this->methodBody($code, $action);
            self::assertStringNotContainsString(
                'auditService->log(',
                $body,
                "EventAdminController::$action() darf auditService NICHT direkt aufrufen. "
                . "Audit laeuft ausschliesslich ueber TaskTreeService (Phase-1-Regel)."
            );
        }
    }

    // =========================================================================
    // Gruppe G — Read-Only-Detail-Ansicht (Phase 3c)
    // =========================================================================

    public function test_show_action_passes_treeEditorEnabled_to_view(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'show');
        foreach (['treeEditorEnabled', 'hasTreeStructure', 'treeData'] as $key) {
            self::assertMatchesRegularExpression(
                "/'$key'\\s*=>/",
                $body,
                "EventAdminController::show() muss '$key' an die View uebergeben "
                . "(Phase 3c)."
            );
        }
    }

    public function test_show_action_computes_hasTreeStructure_without_extra_query(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'show');

        // $tasks wird fuer die bestehende flache Tabelle ohnehin geladen —
        // hasTreeStructure muss darueber iterieren, nicht eine separate
        // Query absetzen.
        self::assertStringContainsString(
            '$tasks = $this->taskRepo->findByEvent',
            $body,
            'EventAdminController::show() laedt $tasks einmalig per findByEvent.'
        );
        // Kein zweiter findByEvent-Call
        $count = substr_count($body, '$this->taskRepo->findByEvent');
        self::assertSame(
            1,
            $count,
            'EventAdminController::show() darf taskRepo->findByEvent() nur einmal aufrufen '
            . '(hasTreeStructure ueber das Ergebnis iterieren, keine Extra-Query).'
        );
        // Struktur-Check iteriert ueber $tasks
        self::assertMatchesRegularExpression(
            '/foreach\s*\(\s*\$tasks\s+as\s+/',
            $body,
            'EventAdminController::show() muss ueber $tasks iterieren, um '
            . 'hasTreeStructure zu bestimmen.'
        );
    }

    public function test_show_action_calls_aggregator_only_when_tree_exists(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'show');

        // buildTree-Call muss unter dem hasTreeStructure-Guard stehen — so
        // fahren Flag=0 oder flache Events ohne unnoetigen Aggregator-Call.
        $posGuard = strpos($body, 'if ($hasTreeStructure)');
        $posBuild = strpos($body, 'treeAggregator->buildTree');
        self::assertNotFalse($posBuild,
            'EventAdminController::show() muss treeAggregator->buildTree() aufrufen.');
        self::assertNotFalse($posGuard,
            'EventAdminController::show() muss hasTreeStructure-Guard um buildTree legen.');
        self::assertLessThan(
            $posBuild,
            $posGuard,
            'Guard (if ($hasTreeStructure)) muss VOR dem buildTree()-Aufruf stehen.'
        );
    }

    public function test_show_view_switches_on_flag_and_structure(): void
    {
        $show = $this->read(self::VIEW_SHOW);
        // View-Switch im Template: if ($treeEditorEnabled && $hasTreeStructure)
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$treeEditorEnabled\s*&&\s*\$hasTreeStructure\s*\)/',
            $show,
            'admin/events/show.php muss View-Switch "if ($treeEditorEnabled && '
            . '$hasTreeStructure)" enthalten — sonst greift der Baum-Pfad inkonsistent.'
        );
    }

    // =========================================================================
    // Gruppe H — Task-Status-Farbkodierung (Phase 4 I7b3)
    // =========================================================================

    public function test_serializeTreeForJson_includes_status_field(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'serializeTreeForJson');

        self::assertNotSame('', $body, 'serializeTreeForJson() fehlt.');
        self::assertMatchesRegularExpression(
            "/'status'\\s*=>/",
            $body,
            'serializeTreeForJson() muss ein status-Feld pro Knoten setzen '
            . '(I7b3 Phase 2). Ohne das Feld koennte JS den Status nicht ueber '
            . 'die showTaskTree-JSON-Response konsumieren.'
        );
        // Der Wert kommt aus $node['status']?->value — nullsafe, damit null-
        // Status korrekt als JSON-null gerendert wird.
        self::assertStringContainsString(
            "\$node['status']?->value",
            $body,
            'serializeTreeForJson() muss nullsafe auf ->value zugreifen, damit '
            . 'Aggregator-Nullwerte als JSON-null durchgereicht werden.'
        );
    }

    public function test_task_tree_node_partial_renders_status_class(): void
    {
        $partial = $this->read(self::PARTIAL_EDITOR);

        // $status-Variable kommt aus $node['status'].
        self::assertMatchesRegularExpression(
            "/\\\$status\\s*=\\s*\\\$node\\['status'\\]/",
            $partial,
            '_task_tree_node.php muss $status aus $node[\'status\'] ziehen.'
        );

        // cssClass() wird als CSS-Klasse am Wurzel-LI ausgegeben.
        self::assertStringContainsString(
            '$status->cssClass()',
            $partial,
            '_task_tree_node.php muss $status->cssClass() als Status-Klasse am '
            . '<li> ausgeben.'
        );
    }

    public function test_task_tree_node_partial_renders_status_badge(): void
    {
        $partial = $this->read(self::PARTIAL_EDITOR);

        // Badge-Klasse task-status-badge--<status->value>.
        self::assertStringContainsString(
            'task-status-badge--',
            $partial,
            '_task_tree_node.php muss Status-Badge mit '
            . 'task-status-badge--<value>-Modifier rendern.'
        );
        self::assertStringContainsString(
            '$status->badgeLabel()',
            $partial,
            '_task_tree_node.php muss den Badge-Text ueber badgeLabel() '
            . 'rendern, nicht hart-kodieren.'
        );
    }

    public function test_task_tree_node_partial_respects_null_status(): void
    {
        $partial = $this->read(self::PARTIAL_EDITOR);

        // Status-Rendering muss unter if ($status !== null)-Guard stehen,
        // damit bei null-Status keine leere CSS-Klasse oder leeres Badge
        // erscheint.
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$status\s*!==\s*null\s*\).*?task-status-badge/s',
            $partial,
            '_task_tree_node.php darf das Status-Badge nur bei '
            . '($status !== null) rendern.'
        );
    }

    public function test_task_tree_readonly_partial_renders_status_same_pattern(): void
    {
        $partial = $this->read(self::PARTIAL_READONLY);

        self::assertStringContainsString(
            '$status->cssClass()',
            $partial,
            '_task_tree_readonly.php muss $status->cssClass() rendern '
            . '(Konsistenz zum Editor-Partial).'
        );
        self::assertStringContainsString(
            '$status->badgeLabel()',
            $partial,
            '_task_tree_readonly.php muss $status->badgeLabel() rendern.'
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$status\s*!==\s*null\s*\)/',
            $partial,
            '_task_tree_readonly.php muss null-Toleranz per if-Guard haben.'
        );
    }

    // =========================================================================
    // Gruppe I — Sortierbare Task-Liste (Modul 6 I7b4)
    // =========================================================================

    public function test_tasksByDate_action_exists_and_uses_treeEditorEnabled_flag(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'tasksByDate');

        self::assertNotSame(
            '',
            $body,
            'EventAdminController::tasksByDate() muss existieren (I7b4).'
        );
        self::assertMatchesRegularExpression(
            '/!\s*\$this->treeEditorEnabled\(\)/',
            $body,
            'tasksByDate() muss das Flag events.tree_editor_enabled '
            . 'pruefen und bei 0 mit 404 abbrechen — konsistent zu '
            . 'showTaskTree/createTaskNode.'
        );
    }

    public function test_tasksByDate_calls_assertEventEditPermission(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'tasksByDate');

        self::assertStringContainsString(
            'assertEventEditPermission',
            $body,
            'tasksByDate() darf Event-Daten nur nach Permission-Pruefung '
            . 'rendern (event_admin oder Organizer des Events).'
        );
    }

    public function test_tasksByDate_uses_flattenToList_and_renders_admin_view(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'tasksByDate');

        self::assertMatchesRegularExpression(
            '/\$this->treeAggregator->flattenToList\s*\(/',
            $body,
            'tasksByDate() muss flattenToList() aufrufen, nicht buildTree().'
        );
        self::assertStringContainsString(
            "'admin/events/tasks_by_date'",
            $body,
            'tasksByDate() rendert die Admin-Container-View '
            . '(admin/events/tasks_by_date.php).'
        );
        self::assertMatchesRegularExpression(
            "/'linkTaskTitles'\s*=>\s*true/",
            $body,
            'Admin-Kontext muss linkTaskTitles=true setzen, damit die '
            . 'Titel als Link auf /admin/events/{id} gerendert werden.'
        );
    }

    public function test_tasksByDate_sorts_by_start_at_with_nulls_last(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'tasksByDate');

        self::assertMatchesRegularExpression(
            '/usort\s*\(\s*\$flatList/',
            $body,
            'tasksByDate() muss flatList nach start_at stabil per usort '
            . 'sortieren (PHP 8+ garantiert Stabilitaet; DFS-Reihenfolge '
            . 'bleibt Sekundaer-Schluessel).'
        );
        self::assertMatchesRegularExpression(
            '/getStartAt\(\)/',
            $body,
            'usort-Vergleich muss getStartAt() nutzen.'
        );
    }

    public function test_admin_tasks_by_date_view_includes_shared_partial(): void
    {
        $view = (string) file_get_contents(
            __DIR__ . '/../../../src/app/Views/admin/events/tasks_by_date.php'
        );
        self::assertStringContainsString(
            "include __DIR__ . '/../../events/_task_list_by_date.php'",
            $view,
            'admin/events/tasks_by_date.php muss das gemeinsame Partial '
            . 'events/_task_list_by_date.php einbinden (DRY mit Organizer-View).'
        );
    }
}
