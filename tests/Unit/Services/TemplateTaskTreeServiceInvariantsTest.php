<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\EventTemplateService;
use App\Services\TaskTreeService;
use App\Services\TemplateTaskTreeService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Statische Code-Invarianten fuer TemplateTaskTreeService (Modul 6 I7c).
 *
 * Pattern wie TaskTreeServiceInvariantsTest: Regex/Substring-Checks gegen
 * den File-Inhalt, schnell, kein DB-Bootstrap. Faengt die Fehlerklassen,
 * die bei Parallel-Implementation zwischen Event- und Template-Service
 * unabsichtlich auseinanderlaufen koennten:
 *   - Lock-Bypass: eine Tree-Operation vergisst assertTemplateEditable.
 *   - Flag-Bypass: eine Tree-Operation vergisst assertEnabled.
 *   - Audit-Bypass: eine Mutation ohne AuditService::log.
 *   - Validation-Duplikat: statt Delegation wird validateTaskData
 *     neu implementiert.
 *   - Re-Privatisierung: validateTaskData in EventTemplateService wird
 *     irrtuemlich wieder private, bricht die I7c-Delegation.
 */
final class TemplateTaskTreeServiceInvariantsTest extends TestCase
{
    private const SERVICE_PATH     = __DIR__ . '/../../../src/app/Services/TemplateTaskTreeService.php';
    private const AGGREGATOR_PATH  = __DIR__ . '/../../../src/app/Services/TemplateTaskTreeAggregator.php';
    private const TPL_SERVICE_PATH = __DIR__ . '/../../../src/app/Services/EventTemplateService.php';
    private const TPL_REPO_PATH    = __DIR__ . '/../../../src/app/Repositories/EventTemplateRepository.php';

    /**
     * Public-API des Tree-Services. Write-Operationen sind die Subset-
     * Liste, die Lock-/Flag-/Transaktions-/Audit-Checks brauchen.
     */
    private const TREE_OPERATIONS = [
        'createNode',
        'move',
        'reorderSiblings',
        'convertToGroup',
        'convertToLeaf',
        'deleteNode',
        'updateNode',
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
    // Gruppe A — Flag + Lock + Transaktion + Audit
    // =========================================================================

    public function test_all_tree_operations_present(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        foreach (self::TREE_OPERATIONS as $op) {
            $body = $this->methodBody($code, $op);
            self::assertNotSame(
                '',
                $body,
                "TemplateTaskTreeService::{$op}() muss existieren."
            );
        }
    }

    public function test_every_tree_operation_calls_assertEnabled_before_write(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        foreach (self::TREE_OPERATIONS as $op) {
            $body = $this->methodBody($code, $op);
            $enabledPos = strpos($body, '$this->assertEnabled()');
            self::assertNotFalse(
                $enabledPos,
                "TemplateTaskTreeService::{$op}() muss assertEnabled() rufen, "
                . "bevor irgendein Write passiert (Flag-Gating)."
            );
            // Kein beginTransaction vor assertEnabled.
            $txPos = strpos($body, 'beginTransaction');
            if ($txPos !== false) {
                self::assertLessThan(
                    $txPos,
                    $enabledPos,
                    "{$op}: assertEnabled() muss vor beginTransaction() stehen."
                );
            }
        }
    }

    public function test_write_operations_call_assertTemplateEditable(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        foreach (self::TREE_OPERATIONS as $op) {
            $body = $this->methodBody($code, $op);
            self::assertStringContainsString(
                'assertTemplateEditable',
                $body,
                "TemplateTaskTreeService::{$op}() muss assertTemplateEditable() "
                . "rufen — isCurrent + !hasDerivedEvents-Lock (G1-Invariante)."
            );
        }
    }

    public function test_assertTemplateEditable_checks_isCurrent_and_hasDerivedEvents(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        $body = $this->methodBody($code, 'assertTemplateEditable');

        self::assertStringContainsString(
            '->isCurrent()',
            $body,
            'assertTemplateEditable() muss isCurrent() pruefen.'
        );
        self::assertStringContainsString(
            '->hasDerivedEvents(',
            $body,
            'assertTemplateEditable() muss hasDerivedEvents() pruefen.'
        );
        self::assertMatchesRegularExpression(
            '/BusinessRuleException/',
            $body,
            'assertTemplateEditable() muss BusinessRuleException werfen, wenn '
            . 'der Lock greift (nicht still returnen).'
        );
    }

    public function test_assertEnabled_reads_shared_settings_key(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        $body = $this->methodBody($code, 'assertEnabled');

        // Gemeinsames Flag mit TaskTreeService (G1-Entscheidung Flag).
        self::assertStringContainsString(
            'TaskTreeService::SETTING_ENABLED',
            $body,
            'assertEnabled() muss die geteilte TaskTreeService::SETTING_ENABLED-'
            . 'Konstante nutzen, nicht einen eigenen Settings-Key.'
        );
    }

    public function test_every_mutation_runs_in_transaction(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        foreach (self::TREE_OPERATIONS as $op) {
            $body = $this->methodBody($code, $op);
            // updateNode hat einen Early-Return bei $oldValues === [] vor
            // der Transaction — das ist korrekt (kein Write, kein Audit).
            // Die Transaction selbst muss dennoch vorhanden sein.
            self::assertStringContainsString(
                'beginTransaction',
                $body,
                "{$op}: Mutation muss in einer Transaktion laufen."
            );
            self::assertMatchesRegularExpression(
                '/commit|rollBack/',
                $body,
                "{$op}: Transaktion muss commit oder rollBack rufen."
            );
        }
    }

    public function test_every_mutation_writes_audit_log(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        foreach (self::TREE_OPERATIONS as $op) {
            $body = $this->methodBody($code, $op);
            self::assertStringContainsString(
                '$this->auditService->log(',
                $body,
                "{$op}: Mutation muss ein Audit-Log schreiben."
            );
        }
    }

    public function test_audit_uses_only_catalog_actions(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        // Erlaubte ENUM-Werte laut audit_log-Schema: create, update, delete,
        // restore, login, logout, login_failed, status_change, export,
        // import, config_change, dialog_message. Tree-Service nutzt nur
        // create/update/delete.
        self::assertMatchesRegularExpression(
            "/action:\s*'(create|update|delete)'/",
            $code,
            'Audit-Actions muessen aus dem ENUM-Katalog stammen.'
        );
        // Keine undefinierten ENUM-Werte wie "template_create" etc.
        self::assertDoesNotMatchRegularExpression(
            "/action:\s*'template_/",
            $code,
            'Audit-Action darf kein "template_*"-Freitext sein — ENUM-Katalog-Disziplin.'
        );
    }

    public function test_reorder_logs_with_null_record_id_and_metadata(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        $body = $this->methodBody($code, 'reorderSiblings');

        // Reorder-Konvention aus .claude/rules/07-audit.md:
        // tableName='event_template_tasks', recordId=null, metadata enthaelt
        // operation='reorder', template_id, parent_template_task_id,
        // children_order.
        self::assertMatchesRegularExpression(
            "/tableName:\s*'event_template_tasks'/",
            $body,
            'reorderSiblings muss tableName=event_template_tasks loggen.'
        );
        self::assertMatchesRegularExpression(
            '/recordId:\s*null/',
            $body,
            'reorderSiblings-Audit muss recordId=null setzen (Reorder betrifft '
            . 'mehrere Zeilen, stellvertretende recordId waere irrefuehrend).'
        );
        self::assertMatchesRegularExpression(
            "/'operation'\s*=>\s*'reorder'/",
            $body,
            'reorderSiblings-Audit muss metadata.operation=reorder setzen '
            . '(Auditoren-Suche-Konvention).'
        );
        self::assertMatchesRegularExpression(
            "/'template_id'\s*=>/",
            $body,
            'reorderSiblings-Metadata muss template_id enthalten.'
        );
        self::assertMatchesRegularExpression(
            "/'parent_template_task_id'\s*=>/",
            $body,
            'reorderSiblings-Metadata muss parent_template_task_id enthalten.'
        );
        self::assertMatchesRegularExpression(
            "/'children_order'\s*=>/",
            $body,
            'reorderSiblings-Metadata muss children_order enthalten.'
        );
    }

    // =========================================================================
    // Gruppe B — Validation-Delegation an EventTemplateService
    // =========================================================================

    public function test_validateTaskData_is_public_in_EventTemplateService(): void
    {
        // Reflection-Test: verhindert unabsichtliche Re-Privatisierung, die
        // die Service-Delegation bricht.
        $rm = new ReflectionMethod(EventTemplateService::class, 'validateTaskData');
        self::assertTrue(
            $rm->isPublic(),
            'EventTemplateService::validateTaskData muss public bleiben — '
            . 'TemplateTaskTreeService delegiert dorthin (I7c G1-Entscheidung A). '
            . 'Wird sie wieder private, bricht die Tree-Editor-Validation.'
        );
    }

    public function test_buildLeafPayload_delegates_to_templateService(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        $body = $this->methodBody($code, 'buildLeafPayload');

        self::assertStringContainsString(
            '$this->templateService->validateTaskData(',
            $body,
            'buildLeafPayload muss Validation an EventTemplateService::'
            . 'validateTaskData delegieren — keine Duplikat-Implementierung '
            . '(G1-Entscheidung A).'
        );
    }

    public function test_service_does_not_reimplement_offset_validation(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        // Defensive: wenn jemand versehentlich die Offset-Validation
        // kopiert, hat das File mehrere "default_offset_minutes_start ==="-
        // Vergleiche. Die Delegation laesst die Feldnamen nur als String-
        // Keys im Payload und vermeidet die Branches.
        $inStartChecks = substr_count(
            $code,
            '$data[\'default_offset_minutes_start\']'
        );
        self::assertLessThanOrEqual(
            0,
            $inStartChecks,
            'Keine direkten default_offset_minutes_start-Checks im Service — '
            . 'die Validation liegt zentral in EventTemplateService::validateTaskData.'
        );
    }

    // =========================================================================
    // Gruppe C — Shape-Semantik / Dispatch
    // =========================================================================

    public function test_createNode_dispatches_on_isGroup(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        $body = $this->methodBody($code, 'createNode');

        self::assertStringContainsString(
            'buildGroupPayload',
            $body,
            'createNode muss bei isGroup=1 den Group-Payload-Builder nutzen.'
        );
        self::assertStringContainsString(
            'buildLeafPayload',
            $body,
            'createNode muss bei isGroup=0 den Leaf-Payload-Builder nutzen.'
        );
    }

    public function test_convertToLeaf_requires_kinder_frei(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        $body = $this->methodBody($code, 'convertToLeaf');

        self::assertStringContainsString(
            'countActiveTaskChildren',
            $body,
            'convertToLeaf muss ablehnen, wenn die Gruppe noch Kinder hat '
            . '(countActiveTaskChildren > 0).'
        );
    }

    public function test_deleteNode_rejects_groups_with_children(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        $body = $this->methodBody($code, 'deleteNode');

        self::assertStringContainsString(
            'countActiveTaskChildren',
            $body,
            'deleteNode muss Gruppen mit aktiven Kindern ablehnen '
            . '(analog zum Event-Pattern).'
        );
    }

    public function test_move_prevents_cycle(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        $body = $this->methodBody($code, 'move');

        self::assertStringContainsString(
            'isTaskDescendantOf',
            $body,
            'move muss via Repository-isTaskDescendantOf einen Zyklus '
            . 'erkennen (neues Parent darf nicht im Subtree liegen).'
        );
    }

    public function test_move_enforces_max_depth_including_subtree(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        $body = $this->methodBody($code, 'move');

        self::assertStringContainsString(
            'maxSubtreeDepth',
            $body,
            'move muss die Subtree-Tiefe des zu verschiebenden Knotens '
            . 'beruecksichtigen, nicht nur die Neues-Parent-Tiefe.'
        );
        self::assertStringContainsString(
            'assertWithinMaxDepth',
            $body,
            'move muss assertWithinMaxDepth() rufen.'
        );
    }

    // =========================================================================
    // Gruppe D — Repository-Tree-Erweiterung
    // =========================================================================

    public function test_template_repo_has_new_tree_methods(): void
    {
        $code = $this->read(self::TPL_REPO_PATH);
        foreach (['reorderTaskSiblings', 'maxSubtreeDepth'] as $m) {
            self::assertMatchesRegularExpression(
                '/public function ' . $m . '\b/',
                $code,
                "EventTemplateRepository::{$m}() muss existieren (I7c-neu)."
            );
        }
        // Methoden aus der I7a-Vorbereitung muessen weiterhin da sein,
        // der Service nutzt sie.
        foreach (
            [
                'moveTask',
                'convertTaskToGroup',
                'convertTaskToLeaf',
                'getTaskDepth',
                'isTaskDescendantOf',
                'countActiveTaskChildren',
            ] as $m
        ) {
            self::assertMatchesRegularExpression(
                '/public function ' . $m . '\b/',
                $code,
                "EventTemplateRepository::{$m}() fehlt — I7a-Voraussetzung gekippt?"
            );
        }
    }

    public function test_reorderTaskSiblings_uses_template_id_filter(): void
    {
        $code = $this->read(self::TPL_REPO_PATH);
        $body = $this->methodBody($code, 'reorderTaskSiblings');

        self::assertMatchesRegularExpression(
            '/AND\s+template_id\s*=\s*:tid/',
            $body,
            'reorderTaskSiblings muss die UPDATE-WHERE-Clause um template_id '
            . 'erweitern (Defense-in-Depth gegen manipulierte ID-Streams, '
            . 'analog zum Event-Pendant H1).'
        );
    }
}
