<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Statische Code-Invarianten fuer Modul 6 I7a (Aufgabenbaum).
 *
 * Pattern wie bei EventTemplateServiceInvariantsTest: Regex/Substring-Checks
 * gegen den File-Inhalt — schnell, kein DB-Bootstrap, faengt strukturelle
 * Regressionen vor dem ersten Browser-Klick.
 *
 * Geprueft werden:
 *   - TaskTreeService:
 *       * Operationen vorhanden (createNode, move, reorderSiblings,
 *         convertToGroup, convertToLeaf, softDeleteNode)
 *       * Settings-Flag-Schutz (assertEnabled) in jeder Public-Methode
 *       * Maximaltiefe Service-enforced (assertWithinMaxDepth)
 *       * Transaktion + Audit-Log in jeder Mutation
 *       * Audit benutzt nur ENUM-Werte aus dem Katalog (create/update/delete)
 *       * Reorder loggt mit recordId=null und vollstaendigem metadata
 *       * Strikte Loesch-Semantik (countActiveChildren-Check, kein Cascade)
 *       * Group-Shape-Felder werden im Payload erzwungen (Sentinel
 *         task_type='aufgabe')
 *   - TaskTreeAggregator:
 *       * Aggregator-Knoten enthaelt open_slots_subtree (Default null,
 *         I7b befuellt es)
 *       * Pfad-Helfer fuer iCal vorhanden
 *   - EventTemplateService::deriveEvent:
 *       * kopiert rekursiv ueber copyTemplateTaskSubtree
 *   - EventTemplateRepository::copyTasks:
 *       * rekursiv (kein flaches INSERT ... SELECT mehr)
 *   - Migration 009 (up + down):
 *       * idempotent ueber INFORMATION_SCHEMA
 *       * parent_task_id Self-FK ON DELETE RESTRICT (G1-Delta-Entscheidung)
 *       * is_group + slot_mode NULLable
 *       * chk_et_group_shape mit task_type='aufgabe'-Sentinel
 *       * Settings-Keys events.tree_editor_enabled (0) + events.tree_max_depth (4)
 *       * Down-Skript: SIGNAL 45000 bei vorhandenen Tree-Daten
 */
final class TaskTreeServiceInvariantsTest extends TestCase
{
    private const SERVICE_PATH      = __DIR__ . '/../../../src/app/Services/TaskTreeService.php';
    private const AGGREGATOR_PATH   = __DIR__ . '/../../../src/app/Services/TaskTreeAggregator.php';
    private const TPL_SERVICE_PATH  = __DIR__ . '/../../../src/app/Services/EventTemplateService.php';
    private const TPL_REPO_PATH     = __DIR__ . '/../../../src/app/Repositories/EventTemplateRepository.php';
    private const TASK_REPO_PATH    = __DIR__ . '/../../../src/app/Repositories/EventTaskRepository.php';
    private const MIGRATION_UP      = __DIR__ . '/../../../scripts/database/migrations/009_event_task_tree.sql';
    private const MIGRATION_DOWN    = __DIR__ . '/../../../scripts/database/migrations/009_event_task_tree.down.sql';

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    /**
     * Liest den Methoden-Body inkl. Signatur-Zeile bis zur naechsten
     * Method-Definition oder Klassen-Ende.
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

    // ========================================================================
    // TaskTreeService — Operationen vorhanden
    // ========================================================================

    public function test_all_tree_operations_present(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        foreach (['createNode', 'move', 'reorderSiblings', 'convertToGroup', 'convertToLeaf', 'softDeleteNode'] as $m) {
            self::assertNotSame(
                '',
                $this->methodBody($code, $m),
                "TaskTreeService::$m() fehlt — G1-Plan verlangt diese Operation."
            );
        }
    }

    // ========================================================================
    // Settings-Flag-Schutz
    // ========================================================================

    public function test_each_public_method_calls_assert_enabled(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        foreach (['createNode', 'move', 'reorderSiblings', 'convertToGroup', 'convertToLeaf', 'softDeleteNode'] as $m) {
            $body = $this->methodBody($code, $m);
            self::assertStringContainsString(
                'assertEnabled',
                $body,
                "TaskTreeService::$m() muss assertEnabled() aufrufen, "
                . 'damit der Service nicht vor Settings-Freigabe wirkt.'
            );
        }
    }

    public function test_assert_enabled_reads_settings_flag(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        $body = $this->methodBody($code, 'assertEnabled');

        self::assertNotSame('', $body, 'assertEnabled() fehlt.');
        self::assertStringContainsString(
            'events.tree_editor_enabled',
            $code,
            'TaskTreeService muss den Setting-Key events.tree_editor_enabled referenzieren.'
        );
        self::assertStringContainsString(
            'BusinessRuleException',
            $body,
            'assertEnabled() muss BusinessRuleException werfen, wenn Flag aus.'
        );
    }

    // ========================================================================
    // Tiefen-Pruefung Service-enforced
    // ========================================================================

    public function test_create_node_enforces_max_depth(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        $body = $this->methodBody($code, 'createNode');
        self::assertStringContainsString(
            'assertWithinMaxDepth',
            $body,
            'createNode() muss assertWithinMaxDepth() aufrufen (G1-Entscheidung Maximaltiefe 4).'
        );
    }

    public function test_move_enforces_max_depth_including_subtree(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        $body = $this->methodBody($code, 'move');

        self::assertStringContainsString(
            'maxSubtreeDepth',
            $body,
            'move() muss maxSubtreeDepth des verschobenen Knotens beruecksichtigen, '
            . 'sonst kann ein tiefer Subtree die Maximaltiefe sprengen.'
        );
        self::assertStringContainsString(
            'assertWithinMaxDepth',
            $body,
            'move() muss assertWithinMaxDepth() aufrufen.'
        );
    }

    public function test_max_depth_setting_key_present(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        self::assertStringContainsString(
            'events.tree_max_depth',
            $code,
            'TaskTreeService muss den Setting-Key events.tree_max_depth referenzieren.'
        );
    }

    // ========================================================================
    // Zyklus-Schutz beim Move
    // ========================================================================

    public function test_move_prevents_cycle(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        $body = $this->methodBody($code, 'move');
        self::assertStringContainsString(
            'isDescendantOf',
            $body,
            'move() muss isDescendantOf() pruefen, um Zyklen zu verhindern '
            . '(Verschieben in eigenen Subtree).'
        );
    }

    // ========================================================================
    // Transaktion + Audit
    // ========================================================================

    public function test_each_mutation_runs_in_transaction(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        foreach (['createNode', 'move', 'reorderSiblings', 'convertToGroup', 'convertToLeaf', 'softDeleteNode'] as $m) {
            $body = $this->methodBody($code, $m);
            self::assertMatchesRegularExpression(
                '/beginTransaction.*?commit/s',
                $body,
                "TaskTreeService::$m() muss in PDO-Transaction laufen."
            );
            self::assertMatchesRegularExpression(
                '/catch\s*\(.*?rollBack/s',
                $body,
                "TaskTreeService::$m() muss bei Exception rollbacken."
            );
        }
    }

    public function test_each_mutation_writes_audit_log(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        foreach (['createNode', 'move', 'reorderSiblings', 'convertToGroup', 'convertToLeaf', 'softDeleteNode'] as $m) {
            $body = $this->methodBody($code, $m);
            self::assertStringContainsString(
                'auditService->log(',
                $body,
                "TaskTreeService::$m() muss auditService->log() aufrufen (G6-Pflicht)."
            );
        }
    }

    // ========================================================================
    // Audit-ENUM-Katalog (keine neuen Werte erfunden)
    // ========================================================================

    public function test_audit_uses_only_catalog_actions(): void
    {
        $code = $this->read(self::SERVICE_PATH);

        // Erlaubt sind: create, update, delete (keine neuen ENUM-Werte!)
        // Wir greppen alle action: 'xxx' und pruefen, dass nur diese drei vorkommen.
        preg_match_all("/action:\s*'([a-z_]+)'/", $code, $m);
        $used = array_unique($m[1] ?? []);
        sort($used);

        $allowed = ['create', 'delete', 'update'];
        $unexpected = array_diff($used, $allowed);

        self::assertSame(
            [],
            $unexpected,
            'TaskTreeService verwendet Audit-Action(s), die nicht im ENUM-Katalog stehen: '
            . implode(', ', $unexpected) . '. Erlaubt: create/update/delete.'
        );
    }

    public function test_create_node_logs_create(): void
    {
        $body = $this->methodBody($this->read(self::SERVICE_PATH), 'createNode');
        self::assertStringContainsString("action: 'create'", $body);
        self::assertStringContainsString("tableName: 'event_tasks'", $body);
    }

    public function test_soft_delete_logs_delete(): void
    {
        $body = $this->methodBody($this->read(self::SERVICE_PATH), 'softDeleteNode');
        self::assertStringContainsString("action: 'delete'", $body);
        self::assertStringContainsString("tableName: 'event_tasks'", $body);
    }

    public function test_reorder_logs_with_null_record_id_and_metadata(): void
    {
        $body = $this->methodBody($this->read(self::SERVICE_PATH), 'reorderSiblings');

        self::assertStringContainsString("action: 'update'", $body);
        self::assertStringContainsString('recordId: null', $body);
        self::assertStringContainsString("'children_order'", $body);
        self::assertStringContainsString("'parent_task_id'", $body);
        self::assertStringContainsString("'event_id'", $body);
        self::assertStringContainsString("'operation'", $body);
    }

    // ========================================================================
    // Strikte Loesch-Semantik (G1-Entscheidung b)
    // ========================================================================

    public function test_soft_delete_rejects_groups_with_active_children(): void
    {
        $body = $this->methodBody($this->read(self::SERVICE_PATH), 'softDeleteNode');
        self::assertStringContainsString(
            'countActiveChildren',
            $body,
            'softDeleteNode() muss aktive Kinder zaehlen und Loeschen verhindern '
            . '(G1-Entscheidung: strikte Ablehnung, kein kaskadierender Soft-Delete).'
        );
    }

    public function test_convert_to_leaf_rejects_groups_with_children(): void
    {
        $body = $this->methodBody($this->read(self::SERVICE_PATH), 'convertToLeaf');
        self::assertStringContainsString(
            'countActiveChildren',
            $body,
            'convertToLeaf() darf eine Gruppe mit Kindern nicht in eine Aufgabe konvertieren.'
        );
    }

    public function test_convert_to_group_rejects_leaves_with_assignments(): void
    {
        $body = $this->methodBody($this->read(self::SERVICE_PATH), 'convertToGroup');
        self::assertStringContainsString(
            'countActiveAssignments',
            $body,
            'convertToGroup() darf einen Leaf mit aktiven Zusagen nicht in eine Gruppe konvertieren.'
        );
    }

    // ========================================================================
    // Group-Shape-Sentinel (G1-Delta-Klaerung: task_type='aufgabe')
    // ========================================================================

    public function test_group_payload_enforces_shape(): void
    {
        $body = $this->methodBody($this->read(self::SERVICE_PATH), 'buildGroupPayload');
        self::assertNotSame('', $body, 'buildGroupPayload() fehlt im TaskTreeService.');

        // Slot-/Helfer-/Stunden-/Capacity-Felder muessen erzwungen werden,
        // damit chk_et_group_shape niemals beruehrt wird.
        self::assertStringContainsString("'is_group'        => 1", $body);
        self::assertStringContainsString("'slot_mode'       => null", $body);
        self::assertStringContainsString("'capacity_target' => null", $body);
        self::assertStringContainsString("'hours_default'   => 0.0", $body);
        // Sentinel: task_type='aufgabe' fuer Gruppen
        self::assertStringContainsString(
            'TYPE_AUFGABE',
            $body,
            'buildGroupPayload() muss task_type auf TYPE_AUFGABE setzen '
            . '(Sentinel-Entscheidung G1-Delta).'
        );
    }

    // ========================================================================
    // TaskTreeAggregator
    // ========================================================================

    public function test_aggregator_node_includes_open_slots_subtree(): void
    {
        $code = $this->read(self::AGGREGATOR_PATH);
        self::assertStringContainsString(
            'open_slots_subtree',
            $code,
            'Aggregator-Knoten muss open_slots_subtree-Property haben (Default null in I7a, '
            . 'wird in I7b befuellt — bestaetigte API-Vereinbarung).'
        );
        // Default null: explizit
        self::assertMatchesRegularExpression(
            "/'open_slots_subtree'\s*=>\s*null/",
            $code,
            'open_slots_subtree muss in I7a explizit auf null gesetzt sein.'
        );
    }

    public function test_aggregator_provides_path_helpers_for_ical(): void
    {
        $code = $this->read(self::AGGREGATOR_PATH);
        foreach (['getAncestorPath', 'getPathString', 'getCategoryNames'] as $method) {
            self::assertStringContainsString(
                "function $method",
                $code,
                "Aggregator muss $method() bieten (iCal-DESCRIPTION/CATEGORIES + Editor-Breadcrumb)."
            );
        }
    }

    public function test_aggregator_summary_fields_present(): void
    {
        $code = $this->read(self::AGGREGATOR_PATH);
        foreach (['helpers_subtree', 'hours_subtree', 'leaves_subtree'] as $field) {
            self::assertStringContainsString(
                $field,
                $code,
                "Aggregator-Knoten muss $field-Property liefern (G1-Plan + G1-Delta-API)."
            );
        }
    }

    // ========================================================================
    // EventTemplateService::deriveEvent — rekursiv
    // ========================================================================

    public function test_derive_event_copies_subtree_recursively(): void
    {
        $code = $this->read(self::TPL_SERVICE_PATH);
        $body = $this->methodBody($code, 'deriveEvent');

        self::assertStringContainsString(
            'copyTemplateTaskSubtree',
            $body,
            'deriveEvent() muss die rekursive Subtree-Kopie verwenden, nicht den alten flachen Pfad.'
        );

        // Helper-Methode existiert
        self::assertNotSame(
            '',
            $this->methodBody($code, 'copyTemplateTaskSubtree'),
            'copyTemplateTaskSubtree() fehlt in EventTemplateService.'
        );
    }

    public function test_subtree_copier_maps_parent_ids(): void
    {
        $body = $this->methodBody($this->read(self::TPL_SERVICE_PATH), 'copyTemplateTaskSubtree');
        self::assertStringContainsString(
            "'parent_task_id'",
            $body,
            'copyTemplateTaskSubtree() muss parent_task_id in den neuen event_tasks-Eintrag setzen.'
        );
        self::assertStringContainsString(
            'findTaskChildren',
            $body,
            'copyTemplateTaskSubtree() muss Children rekursiv per findTaskChildren laden.'
        );
    }

    // ========================================================================
    // EventTemplateRepository::copyTasks — rekursiv
    // ========================================================================

    public function test_copy_tasks_is_recursive_not_flat_insert_select(): void
    {
        $code = $this->read(self::TPL_REPO_PATH);
        $body = $this->methodBody($code, 'copyTasks');

        // Ein flacher INSERT ... SELECT wuerde parent-IDs nicht mappen — das
        // duerfte nicht mehr im copyTasks-Body stehen.
        self::assertStringNotContainsString(
            'INSERT INTO event_template_tasks',
            $body,
            'copyTasks() darf keinen flachen INSERT ... SELECT mehr enthalten — '
            . 'die parent_template_task_id-Mapping erfordert rekursive Kopie.'
        );
        self::assertStringContainsString(
            'copyTaskSubtree',
            $body,
            'copyTasks() muss copyTaskSubtree() verwenden (rekursive Kopie ueber Hierarchie).'
        );
    }

    // ========================================================================
    // EventTaskRepository — Tree-Methoden
    // ========================================================================

    public function test_event_task_repository_has_tree_methods(): void
    {
        $code = $this->read(self::TASK_REPO_PATH);
        foreach ([
            'findChildren',
            'countActiveChildren',
            'getDepth',
            'maxSubtreeDepth',
            'isDescendantOf',
            'move',
            'reorderSiblings',
            'convertToGroup',
            'convertToLeaf',
            'getAncestorPath',
        ] as $m) {
            self::assertStringContainsString(
                "function $m",
                $code,
                "EventTaskRepository::$m() fehlt — Service kann ohne diese Methode nicht arbeiten."
            );
        }
    }

    public function test_create_uses_array_key_exists_for_slot_mode(): void
    {
        $body = $this->methodBody($this->read(self::TASK_REPO_PATH), 'create');
        self::assertStringContainsString(
            "array_key_exists('slot_mode', \$data)",
            $body,
            'create() muss slot_mode mit array_key_exists pruefen, sonst wird '
            . 'explizit uebergebenes null (Gruppe) durch ?? auf SLOT_FIX umgewandelt — '
            . 'das verletzt chk_et_group_shape.'
        );
    }

    // ========================================================================
    // Migration 009 — Schema
    // ========================================================================

    public function test_migration_is_idempotent(): void
    {
        $up = $this->read(self::MIGRATION_UP);
        self::assertStringContainsString(
            'INFORMATION_SCHEMA.COLUMNS',
            $up,
            'Migration 009 muss INFORMATION_SCHEMA-Pattern fuer Idempotenz nutzen '
            . '(Lesson 18.04. MariaDB-Portabilitaet).'
        );
        self::assertStringContainsString(
            'INFORMATION_SCHEMA.TABLE_CONSTRAINTS',
            $up,
            'Migration 009 muss FK-Existenz via TABLE_CONSTRAINTS pruefen.'
        );
        self::assertStringContainsString(
            'INFORMATION_SCHEMA.CHECK_CONSTRAINTS',
            $up,
            'Migration 009 muss CHECK-Constraint-Existenz via CHECK_CONSTRAINTS pruefen.'
        );
    }

    public function test_migration_adds_tree_columns(): void
    {
        $up = $this->read(self::MIGRATION_UP);
        self::assertStringContainsString('parent_task_id', $up);
        self::assertStringContainsString('parent_template_task_id', $up);
        self::assertStringContainsString('is_group', $up);
        self::assertStringContainsString('idx_et_parent_sort', $up);
        self::assertStringContainsString('idx_ett_parent_sort', $up);
    }

    public function test_self_fk_is_on_delete_restrict(): void
    {
        $up = $this->read(self::MIGRATION_UP);

        // Beide Self-FKs muessen RESTRICT sein (G1-Delta: bewusst, Tree-
        // Aufraeumen erfolgt via Service).
        self::assertMatchesRegularExpression(
            '/fk_et_parent.*?REFERENCES\s+event_tasks\(id\)\s+ON\s+DELETE\s+RESTRICT/s',
            $up,
            'fk_et_parent muss ON DELETE RESTRICT sein (G1-Delta).'
        );
        self::assertMatchesRegularExpression(
            '/fk_ett_parent.*?REFERENCES\s+event_template_tasks\(id\)\s+ON\s+DELETE\s+RESTRICT/s',
            $up,
            'fk_ett_parent muss ON DELETE RESTRICT sein (G1-Delta).'
        );
    }

    public function test_slot_mode_becomes_nullable(): void
    {
        $up = $this->read(self::MIGRATION_UP);
        // SQL-Escape: dynamische SQL-Strings escapen Single-Quote durch
        // Verdopplung — also ''fix'' statt 'fix' im File.
        self::assertMatchesRegularExpression(
            "/MODIFY COLUMN slot_mode ENUM\\(''fix'',\\s*''variabel''\\)\\s+NULL/i",
            $up,
            'Migration 009 muss slot_mode auf NULLable umstellen (Gruppen haben keinen Slot-Modus).'
        );
    }

    public function test_group_shape_check_uses_aufgabe_sentinel(): void
    {
        $up = $this->read(self::MIGRATION_UP);

        // chk_et_group_shape muss den Sentinel task_type='aufgabe' enthalten
        // (G1-Delta-Entscheidung statt task_type NULLable).
        self::assertMatchesRegularExpression(
            '/chk_et_group_shape.*?task_type\s*=\s*\'\'aufgabe\'\'/s',
            $up,
            "chk_et_group_shape muss Sentinel task_type='aufgabe' fuer Gruppen erzwingen "
            . '(G1-Delta-Bestaetigung).'
        );
        self::assertMatchesRegularExpression(
            '/chk_ett_group_shape.*?task_type\s*=\s*\'\'aufgabe\'\'/s',
            $up,
            "chk_ett_group_shape muss Sentinel task_type='aufgabe' enthalten."
        );
    }

    public function test_chk_et_fix_times_excludes_groups(): void
    {
        $up = $this->read(self::MIGRATION_UP);
        // Der neue chk_et_fix_times muss is_group=1 als Eskape erlauben,
        // sonst greift der Check auf Gruppen mit start_at=NULL.
        self::assertMatchesRegularExpression(
            '/chk_et_fix_times.*?is_group\s*=\s*1/s',
            $up,
            'chk_et_fix_times muss is_group=1 als Eskape vorsehen, sonst sperrt es Gruppen.'
        );
    }

    public function test_settings_keys_seeded(): void
    {
        $up = $this->read(self::MIGRATION_UP);
        self::assertStringContainsString(
            'events.tree_editor_enabled',
            $up,
            'Migration 009 muss Setting events.tree_editor_enabled seeden (Default 0).'
        );
        self::assertStringContainsString(
            'events.tree_max_depth',
            $up,
            'Migration 009 muss Setting events.tree_max_depth seeden (Default 4).'
        );
        // Default-Werte: Editor aus, Maximaltiefe 4
        self::assertMatchesRegularExpression(
            "/events\\.tree_editor_enabled.*?'0'/s",
            $up,
            'events.tree_editor_enabled muss als Default 0 (aus) gesetzt werden.'
        );
        self::assertMatchesRegularExpression(
            "/events\\.tree_max_depth.*?'4'/s",
            $up,
            'events.tree_max_depth muss als Default 4 gesetzt werden.'
        );
    }

    // ========================================================================
    // Migration 009 Down — Sicherheits-Check
    // ========================================================================

    public function test_down_migration_aborts_when_tree_data_exists(): void
    {
        $down = $this->read(self::MIGRATION_DOWN);
        // Seit G2-Nachbesserung 2 steht der SIGNAL-Aufruf direkt im Body einer
        // kurzlebigen Stored Procedure (PREPARE/EXECUTE erlaubt SIGNAL nicht,
        // MySQL-Fehler 1295). Damit ohne SQL-Escape-Verdopplung der Apostrophe.
        self::assertStringContainsString(
            "SIGNAL SQLSTATE '45000'",
            $down,
            'Down-Migration 009 muss bei vorhandenen Tree-Daten via SIGNAL 45000 abbrechen, '
            . 'damit kein Datenverlust.'
        );
        self::assertStringContainsString(
            'parent_task_id IS NOT NULL OR is_group = 1',
            $down,
            'Down-Migration muss Tree-Daten ueber parent_task_id IS NOT NULL OR is_group=1 erkennen.'
        );
    }

    public function test_down_migration_removes_settings_keys(): void
    {
        $down = $this->read(self::MIGRATION_DOWN);
        self::assertStringContainsString(
            'events.tree_editor_enabled',
            $down,
            'Down-Migration muss events.tree_editor_enabled entfernen.'
        );
        self::assertStringContainsString(
            'events.tree_max_depth',
            $down,
            'Down-Migration muss events.tree_max_depth entfernen.'
        );
    }
}
