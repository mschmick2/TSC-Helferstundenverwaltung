<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invarianten fuer EventCompletionService (Modul 6 I3).
 *
 * Verifiziert die kritischen Vertraege, die an anderer Stelle (Self-Approval-
 * Guard im WorkflowService, Audit-Integritaet, Schema-Konsistenz) haengen.
 */
final class EventCompletionServiceInvariantsTest extends TestCase
{
    private const SERVICE_FILE = __DIR__ . '/../../../src/app/Services/EventCompletionService.php';

    public function test_work_entries_are_created_as_eingereicht(): void
    {
        $code = (string) file_get_contents(self::SERVICE_FILE);

        self::assertMatchesRegularExpression(
            '/[\'"]status[\'"]\s*=>\s*[\'"]eingereicht[\'"]/',
            $code,
            'Auto-generierte work_entries muessen direkt als "eingereicht" angelegt werden '
            . '(aus R2 in I1-G1 festgelegt - kein entwurf-Zwischenschritt).'
        );
    }

    public function test_work_entries_have_origin_event(): void
    {
        $code = (string) file_get_contents(self::SERVICE_FILE);

        self::assertMatchesRegularExpression(
            '/[\'"]origin[\'"]\s*=>\s*[\'"]event[\'"]/',
            $code,
            'Auto-generierte work_entries muessen origin=event haben, '
            . 'damit Preufansicht sie kennzeichnen kann.'
        );
    }

    public function test_created_by_is_system_user(): void
    {
        $code = (string) file_get_contents(self::SERVICE_FILE);

        self::assertStringContainsString(
            'getSystemUserId()',
            $code,
            'created_by_user_id muss auf System-User zeigen (nicht auf Eventadmin), '
            . 'damit der Selbstgenehmigungs-Guard in WorkflowService '
            . '(Pruefung auf entry.created_by_user_id) nicht falsch feuert.'
        );
    }

    public function test_uses_transaction_for_atomicity(): void
    {
        $code = (string) file_get_contents(self::SERVICE_FILE);

        self::assertStringContainsString(
            'beginTransaction()',
            $code,
            'completeEvent muss in einer Transaktion laufen '
            . '(Event-Status + N work_entries + N assignment-Status atomar).'
        );
        self::assertStringContainsString(
            'rollBack()',
            $code,
            'Bei Fehler muss zurueckgerollt werden.'
        );
        self::assertStringContainsString(
            'commit()',
            $code,
            'Bei Erfolg muss committed werden.'
        );
    }

    public function test_audit_log_is_called_for_all_state_changes(): void
    {
        $code = (string) file_get_contents(self::SERVICE_FILE);

        // Drei Audit-Punkte: event status_change, work_entry create,
        // assignment status_change
        $auditCount = substr_count($code, '$this->audit->log(');

        self::assertGreaterThanOrEqual(
            3,
            $auditCount,
            'Mindestens 3 audit->log()-Aufrufe erwartet: '
            . 'event status_change, work_entry create, assignment status_change.'
        );
    }

    public function test_only_confirmed_assignments_are_processed(): void
    {
        $code = (string) file_get_contents(self::SERVICE_FILE);

        self::assertStringContainsString(
            'findConfirmedForEvent',
            $code,
            'Nur bestaetigte Zusagen werden zu work_entries. '
            . 'Vorgeschlagene/angefragte Stornos muessen vorher entschieden sein.'
        );
    }

    public function test_requires_veroeffentlicht_status(): void
    {
        $code = (string) file_get_contents(self::SERVICE_FILE);

        self::assertStringContainsString(
            'STATUS_VEROEFFENTLICHT',
            $code,
            'Nur veroeffentlichte Events duerfen abgeschlossen werden '
            . '(Schutz vor Double-Complete oder Abschluss von Entwuerfen).'
        );
    }

    public function test_migration_003_creates_system_user(): void
    {
        $migration = (string) file_get_contents(
            __DIR__ . '/../../../scripts/database/migrations/003_system_user.sql'
        );

        self::assertStringContainsString(
            "'SYSTEM'",
            $migration,
            'Migration 003 muss SYSTEM-User anlegen.'
        );
        self::assertMatchesRegularExpression(
            '/is_active\s*=\s*FALSE|is_active.*FALSE/',
            $migration,
            'System-User muss is_active=FALSE haben (kein Login).'
        );
        self::assertMatchesRegularExpression(
            '/password_hash\s*=?\s*NULL|password_hash.*NULL/',
            $migration,
            'System-User muss password_hash=NULL haben.'
        );
    }

    public function test_completion_cancels_pending_event_jobs(): void
    {
        $code = (string) file_get_contents(self::SERVICE_FILE);

        // Drei unique_keys, die im Event-Lebenszyklus eingeplant wurden
        // (publish dispatcht :reminder:7d/:reminder:24h/:completion_reminder),
        // muessen beim Abschluss storniert werden — sonst landen Reminder-Mails
        // fuer ein bereits abgeschlossenes Event in den Mailboxen.
        $expectedCancelKeys = [
            'reminder:7d',
            'reminder:24h',
            'completion_reminder',
        ];

        foreach ($expectedCancelKeys as $key) {
            self::assertMatchesRegularExpression(
                '/scheduler->cancel\(\s*"event:\{.*\}:' . preg_quote($key, '/') . '"/',
                $code,
                "EventCompletionService muss beim Abschluss event:*:{$key} stornieren."
            );
        }
    }

    public function test_scheduler_is_optional_constructor_arg(): void
    {
        $code = (string) file_get_contents(self::SERVICE_FILE);

        // Das ?SchedulerService = null-Pattern ist Pflicht: Es haelt die existierenden
        // Tests gruen, ohne dass jeder Service-Test einen Scheduler injizieren muss.
        self::assertMatchesRegularExpression(
            '/\?SchedulerService\s+\$\w+\s*=\s*null/',
            $code,
            'SchedulerService muss als optional ?SchedulerService = null injiziert werden.'
        );
    }
}
