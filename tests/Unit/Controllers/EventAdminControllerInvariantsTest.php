<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Statische Code-Invarianten gegen die G3-Lesson (2026-04-17):
 *   Der Bug `$user['id']` statt `$user->getId()` wurde erst beim Review
 *   gefangen, weil KEIN echter Feature-Test die Controller ausfuehrt.
 *   Bis Feature-Tests in einem spaeteren Increment existieren, fangen
 *   diese Invarianten-Checks das wichtigste Pattern statisch ab.
 */
final class EventAdminControllerInvariantsTest extends TestCase
{
    private const CONTROLLERS_DIR = __DIR__ . '/../../../src/app/Controllers';

    private const EVENT_CONTROLLERS = [
        'EventAdminController.php',
        'EventTemplateController.php',
    ];

    public function test_no_array_access_on_user_object(): void
    {
        foreach (self::EVENT_CONTROLLERS as $file) {
            $code = (string) file_get_contents(self::CONTROLLERS_DIR . '/' . $file);

            // `$user['...']` waere ein Bug, weil User ein Objekt mit Gettern ist
            self::assertDoesNotMatchRegularExpression(
                '/\$user\[[\'"]/',
                $code,
                "$file: \$user['key'] Array-Access auf User-Objekt gefunden. "
                . 'Nutze \$user->getId()/getEmail()/etc. '
                . 'Regression des G3-Findings vom 2026-04-17.'
            );
        }
    }

    public function test_all_write_actions_call_auditService_log(): void
    {
        // Jede POST-Route-Methode (store/update/delete/publish/cancel/addTask/deleteTask)
        // muss mindestens einen auditService->log()-Aufruf haben.
        $code = (string) file_get_contents(
            self::CONTROLLERS_DIR . '/EventAdminController.php'
        );

        $writeMethods = ['store', 'update', 'publish', 'cancel', 'delete', 'addTask', 'deleteTask'];
        foreach ($writeMethods as $method) {
            // Naive Extraktion: von "public function X(" bis zur naechsten "public function " oder Datei-Ende
            $pattern = '/public function ' . preg_quote($method, '/') . '\s*\([^{]*\{(.*?)(?=public function |\z)/s';
            self::assertMatchesRegularExpression(
                $pattern,
                $code,
                "EventAdminController::$method() nicht im Code gefunden."
            );

            preg_match($pattern, $code, $matches);
            $methodBody = $matches[1] ?? '';

            self::assertStringContainsString(
                'auditService->log(',
                $methodBody,
                "EventAdminController::$method() enthaelt keinen \$this->auditService->log()-Aufruf "
                . '(G6-Regel: jede Business-Schreibung muss Audit-Log schreiben).'
            );
        }
    }

    public function test_enum_inputs_are_allowlist_validated(): void
    {
        // G4-Fix S1: addTask() muss task_type/slot_mode/capacity_mode
        // gegen Model-Konstanten validieren, bevor an Repository weitergereicht wird.
        $code = (string) file_get_contents(
            self::CONTROLLERS_DIR . '/EventAdminController.php'
        );

        // Muss die 3 ENUM-Variablen gegen Allowlists pruefen
        foreach (['TYPE_AUFGABE', 'TYPE_BEIGABE', 'SLOT_FIX', 'SLOT_VARIABEL',
                  'CAP_UNBEGRENZT', 'CAP_ZIEL', 'CAP_MAXIMUM'] as $const) {
            self::assertStringContainsString(
                $const,
                $code,
                "EventAdminController.php referenziert nicht die ENUM-Konstante EventTask::$const "
                . '(G4-Fix S1: Allowlist-Validierung muss greifen).'
            );
        }
    }

    public function test_migration_uses_on_delete_restrict_for_pii_fks(): void
    {
        // G5 D1: event_organizers.user_id muss ON DELETE RESTRICT sein,
        // damit Audit-Historie bei User-Loeschung nicht verschwindet.
        $migration = (string) file_get_contents(
            __DIR__ . '/../../../scripts/database/migrations/002_module_events.sql'
        );

        // fk_eo_user muss RESTRICT sein (nicht CASCADE)
        self::assertMatchesRegularExpression(
            '/fk_eo_user\s+FOREIGN KEY.*?REFERENCES users\(id\)\s+ON DELETE RESTRICT/s',
            $migration,
            'Migration 002: fk_eo_user muss ON DELETE RESTRICT sein (DSGVO G5 D1).'
        );

        // event_task_assignments.user_id ebenfalls RESTRICT
        self::assertMatchesRegularExpression(
            '/fk_eta_user\s+FOREIGN KEY.*?REFERENCES users\(id\)\s+ON DELETE RESTRICT/s',
            $migration,
            'Migration 002: fk_eta_user muss ON DELETE RESTRICT sein (Helferstunden-Nachweis).'
        );
    }

    public function test_migration_preserves_audit_log_triggers(): void
    {
        // Die Trigger audit_log_no_update / no_delete duerfen
        // durch die neue Migration NICHT entfernt werden.
        $migration = (string) file_get_contents(
            __DIR__ . '/../../../scripts/database/migrations/002_module_events.sql'
        );

        self::assertStringNotContainsString(
            'DROP TRIGGER audit_log_no_update',
            $migration,
            'Migration 002 darf audit_log_no_update-Trigger NICHT droppen.'
        );
        self::assertStringNotContainsString(
            'DROP TRIGGER audit_log_no_delete',
            $migration,
            'Migration 002 darf audit_log_no_delete-Trigger NICHT droppen.'
        );
    }
}
