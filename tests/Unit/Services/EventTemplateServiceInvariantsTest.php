<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Statische Code-Invarianten fuer Modul 6 I4:
 *   - saveAsNewVersion ist transaktional
 *   - deriveEvent ist transaktional + setzt source_template_id/version
 *   - Audit-Log wird geschrieben (3 Business-Aktionen: addTask, saveAsNew, derive)
 *   - Template-Lock bei hasDerivedEvents
 *   - ENUM-Allowlist-Validierung (G4-Pattern)
 */
final class EventTemplateServiceInvariantsTest extends TestCase
{
    private const SERVICE_PATH = __DIR__ . '/../../../src/app/Services/EventTemplateService.php';
    private const MIGRATION_PATH = __DIR__ . '/../../../scripts/database/migrations/004_events_source_template.sql';

    private function serviceCode(): string
    {
        return (string) file_get_contents(self::SERVICE_PATH);
    }

    public function test_save_as_new_version_uses_transaction(): void
    {
        $code = $this->serviceCode();

        self::assertMatchesRegularExpression(
            '/function\s+saveAsNewVersion.*?beginTransaction.*?commit/s',
            $code,
            'saveAsNewVersion muss in PDO-Transaction laufen.'
        );
        self::assertMatchesRegularExpression(
            '/function\s+saveAsNewVersion.*?catch\s*\(.*?rollBack/s',
            $code,
            'saveAsNewVersion muss bei Exception rollbacken.'
        );
    }

    public function test_derive_event_uses_transaction(): void
    {
        $code = $this->serviceCode();

        self::assertMatchesRegularExpression(
            '/function\s+deriveEvent.*?beginTransaction.*?commit/s',
            $code,
            'deriveEvent muss in PDO-Transaction laufen.'
        );
        self::assertMatchesRegularExpression(
            '/function\s+deriveEvent.*?catch\s*\(.*?rollBack/s',
            $code,
            'deriveEvent muss bei Exception rollbacken.'
        );
    }

    public function test_derive_event_sets_source_template_reference(): void
    {
        $code = $this->serviceCode();

        self::assertMatchesRegularExpression(
            '/function\s+deriveEvent.*?[\'"]source_template_id[\'"].*?=>\s*\$templateId/s',
            $code,
            'deriveEvent muss source_template_id auf Event-Zeile setzen.'
        );
        self::assertMatchesRegularExpression(
            '/function\s+deriveEvent.*?[\'"]source_template_version[\'"].*?getVersion/s',
            $code,
            'deriveEvent muss source_template_version als Snapshot speichern.'
        );
    }

    public function test_mutating_methods_write_audit_log(): void
    {
        $code = $this->serviceCode();

        foreach (['addTask', 'updateTask', 'deleteTask', 'saveAsNewVersion', 'deriveEvent'] as $m) {
            $pattern = '/function\s+' . preg_quote($m, '/') . '\s*\([^{]*\{(.*?)(?=private function |public function |protected function |\}\s*$)/s';
            self::assertMatchesRegularExpression(
                $pattern,
                $code,
                "EventTemplateService::$m() nicht gefunden."
            );
            preg_match($pattern, $code, $matches);
            $body = $matches[1] ?? '';
            self::assertStringContainsString(
                'auditService->log(',
                $body,
                "EventTemplateService::$m() muss auditService->log() aufrufen (G6)."
            );
        }
    }

    public function test_template_is_locked_when_events_derived(): void
    {
        $code = $this->serviceCode();

        foreach (['addTask', 'updateTask', 'deleteTask'] as $m) {
            $pattern = '/function\s+' . preg_quote($m, '/') . '\s*\(.*?\)\s*:.*?\{(.*?)(?=private function |public function |protected function |\}\s*$)/s';
            preg_match($pattern, $code, $matches);
            $body = $matches[1] ?? '';
            self::assertStringContainsString(
                'hasDerivedEvents',
                $body,
                "EventTemplateService::$m() muss hasDerivedEvents() pruefen (Lock-Policy I4)."
            );
        }
    }

    public function test_enum_allowlist_validation_present(): void
    {
        $code = $this->serviceCode();

        foreach ([
            'TYPE_AUFGABE', 'TYPE_BEIGABE',
            'SLOT_FIX', 'SLOT_VARIABEL',
            'CAP_UNBEGRENZT', 'CAP_ZIEL', 'CAP_MAXIMUM',
        ] as $const) {
            self::assertStringContainsString(
                $const,
                $code,
                "EventTemplateService referenziert EventTask::$const nicht "
                . '(G4-Pattern: ENUM-Allowlist-Validation Pflicht).'
            );
        }
    }

    public function test_only_current_version_editable(): void
    {
        $code = $this->serviceCode();

        self::assertMatchesRegularExpression(
            '/isCurrent\(\)/',
            $code,
            'EventTemplateService muss isCurrent()-Flag pruefen '
            . '(Policy: alte Versionen sind read-only).'
        );
    }

    public function test_derive_event_copies_template_tasks(): void
    {
        $code = $this->serviceCode();

        $pattern = '/function\s+deriveEvent.*?foreach\s*\(\s*\$templateTasks/s';
        self::assertMatchesRegularExpression(
            $pattern,
            $code,
            'deriveEvent muss alle Template-Tasks kopieren (Snapshot-Pattern).'
        );
        self::assertMatchesRegularExpression(
            '/eventTaskRepo->create/',
            $code,
            'deriveEvent muss eventTaskRepo->create() fuer jede Template-Task aufrufen.'
        );
    }

    public function test_add_task_audits_into_event_template_tasks_table(): void
    {
        $code = $this->serviceCode();
        $pattern = '/function\s+addTask\s*\([^{]*\{(.*?)(?=private function |public function |protected function |\}\s*$)/s';
        preg_match($pattern, $code, $matches);
        $body = $matches[1] ?? '';

        self::assertStringContainsString(
            "tableName: 'event_template_tasks'",
            $body,
            "addTask() muss Audit-Eintrag auf 'event_template_tasks' schreiben (G6-Fix A-I4-1)."
        );
        self::assertStringContainsString(
            "action: 'create'",
            $body,
            "addTask() muss action='create' loggen, nicht 'update' auf Parent-Template (G6-Fix A-I4-1)."
        );
    }

    public function test_update_task_uses_diff_pattern(): void
    {
        $code = $this->serviceCode();
        $pattern = '/function\s+updateTask\s*\([^{]*\{(.*?)(?=private function |public function |protected function |\}\s*$)/s';
        preg_match($pattern, $code, $matches);
        $body = $matches[1] ?? '';

        self::assertStringContainsString(
            '$diffOld',
            $body,
            'updateTask() muss Diff-Pattern nutzen (G6-Fix A-I4-2: alle geaenderten Felder loggen, nicht nur title).'
        );
        self::assertStringContainsString(
            '$diffNew',
            $body,
            'updateTask() muss Diff-Pattern nutzen (G6-Fix A-I4-2).'
        );
        self::assertMatchesRegularExpression(
            '/foreach\s*\(\s*\$oldSnapshot/',
            $body,
            'updateTask() muss ueber ein Feld-Snapshot iterieren (Diff-Pattern aus rules/07-audit.md).'
        );
    }

    public function test_save_as_new_version_writes_two_audit_entries(): void
    {
        $code = $this->serviceCode();
        $pattern = '/function\s+saveAsNewVersion\s*\([^{]*\{(.*?)(?=private function |public function |protected function |\}\s*$)/s';
        preg_match($pattern, $code, $matches);
        $body = $matches[1] ?? '';

        $calls = substr_count($body, 'auditService->log(');
        self::assertGreaterThanOrEqual(
            2,
            $calls,
            'saveAsNewVersion() muss zwei Audit-Eintraege schreiben: create (neue Version) '
            . 'und update (parent.is_current 1->0) (G6-Fix A-I4-3).'
        );
    }

    public function test_fix_slot_requires_both_offsets(): void
    {
        $code = $this->serviceCode();

        // Pattern: in validateTaskData muss ein Check existieren, der bei
        // slot_mode===SLOT_FIX und fehlenden Offsets eine ValidationException wirft.
        // Sonst schlaegt die DB bei deriveEvent mit chk_et_fix_times fehl.
        self::assertMatchesRegularExpression(
            '/SLOT_FIX.*?offsetStart\s*===\s*null.*?offsetEnd\s*===\s*null/s',
            $code,
            'validateTaskData() muss bei slot_mode=fix beide Offsets erzwingen '
            . '(verhindert Verletzung von chk_et_fix_times in event_tasks).'
        );
    }

    public function test_migration_004_is_idempotent(): void
    {
        $migration = (string) file_get_contents(self::MIGRATION_PATH);

        self::assertStringContainsString(
            'INFORMATION_SCHEMA.COLUMNS',
            $migration,
            'Migration 004 muss Information-Schema-Check fuer Idempotenz verwenden.'
        );
        self::assertStringContainsString(
            'source_template_id',
            $migration,
            'Migration 004 muss source_template_id-Spalte einfuegen.'
        );
        self::assertStringContainsString(
            'ON DELETE SET NULL',
            $migration,
            'Migration 004 FK auf event_templates muss ON DELETE SET NULL sein '
            . '(abgeleitete Events ueberleben Template-Loeschung).'
        );
    }
}
