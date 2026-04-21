<?php

declare(strict_types=1);

namespace Tests\Feature\Cron;

use Tests\Support\FeatureTestCase;

/**
 * Feature-Tests fuer den externen Cron-Pinger-Endpunkt POST /cron/run.
 *
 * Voraussetzungen:
 *   - Migration 006 in vaes_test eingespielt
 *   - Tabellen: settings, scheduled_jobs, scheduler_runs, rate_limits
 *
 * Konvention:
 *   Jeder Test laeuft in eigener Transaktion (FeatureTestCase ⇒ IntegrationTestCase),
 *   die in tearDown() zurueckgerollt wird. Settings, scheduled_jobs und
 *   rate_limits-Eintraege ueberleben den Test daher nicht.
 */
final class CronControllerTest extends FeatureTestCase
{
    private const TOKEN      = 'test-cron-token-please-rotate-in-production';
    private const TOKEN_HASH = '7d33d2b85f01ec4cd28b6f9d4dd6e35f0e5e89d23ae0c18cc72aaeb44a7f01b8';
    // ↑ sha256 der TOKEN-Konstante; in setUpTokenHash() wird derselbe Wert
    //   per hash() berechnet — die Konstante dient nur der Lesbarkeit.

    protected function setUp(): void
    {
        parent::setUp();
        // Sicherstellen, dass kein Vorlauf-State stoert
        $this->pdo()->exec("DELETE FROM scheduled_jobs WHERE job_type = 'dialog_reminder'");
    }

    // -------------------------------------------------------------------------
    // Auth-Schicht
    // -------------------------------------------------------------------------

    public function test_ohne_token_header_liefert_401(): void
    {
        $this->setupTokenHash();
        $this->enableNotifications();

        $response = $this->post('/cron/run');

        self::assertSame(401, $response->getStatusCode());
        $body = json_decode($this->responseBody($response), true);
        self::assertSame('unauthorized', $body['reason'] ?? null);
    }

    public function test_falscher_token_liefert_401(): void
    {
        $this->setupTokenHash();
        $this->enableNotifications();

        $response = $this->post('/cron/run', [], [
            'X-Cron-Token' => 'falscher-token-xyz',
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_ohne_hinterlegten_hash_liefert_401(): void
    {
        // Bewusst KEIN setupTokenHash() — settings.cron_external_token_hash ist NULL
        $this->enableNotifications();

        $response = $this->post('/cron/run', [], [
            'X-Cron-Token' => self::TOKEN,
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Feature-Flag
    // -------------------------------------------------------------------------

    public function test_feature_flag_aus_liefert_503(): void
    {
        $this->setupTokenHash();
        $this->disableNotifications();

        $response = $this->post('/cron/run', [], [
            'X-Cron-Token' => self::TOKEN,
        ]);

        self::assertSame(503, $response->getStatusCode());
        $body = json_decode($this->responseBody($response), true);
        self::assertSame('notifications_disabled', $body['reason'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Min-Intervall
    // -------------------------------------------------------------------------

    public function test_min_intervall_noch_nicht_erreicht_liefert_skipped(): void
    {
        $this->setupTokenHash();
        $this->enableNotifications();
        $this->setSetting('cron_min_interval_seconds', '300');
        $this->setSetting('cron_last_run_at', date('Y-m-d H:i:s'));

        $response = $this->post('/cron/run', [], [
            'X-Cron-Token' => self::TOKEN,
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($this->responseBody($response), true);
        self::assertTrue($body['ok']);
        self::assertTrue($body['skipped']);
        self::assertSame('min_interval_not_reached', $body['reason']);
    }

    // -------------------------------------------------------------------------
    // Happy-Path
    // -------------------------------------------------------------------------

    public function test_korrekter_token_fuehrt_due_jobs_aus(): void
    {
        $this->setupTokenHash();
        $this->enableNotifications();
        $this->setSetting('cron_last_run_at', '2000-01-01 00:00:00'); // weit in der Vergangenheit

        // Faelligen Job einfuegen, der intern silent skipped (work_entry existiert nicht)
        $this->pdo()->prepare(
            "INSERT INTO scheduled_jobs (job_type, payload, run_at, status)
             VALUES ('dialog_reminder', :payload, :run_at, 'pending')"
        )->execute([
            'payload' => json_encode(['work_entry_id' => 99999, 'days_open' => 3]),
            'run_at'  => date('Y-m-d H:i:s', time() - 60),
        ]);

        $response = $this->post('/cron/run', [], [
            'X-Cron-Token' => self::TOKEN,
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($this->responseBody($response), true);
        self::assertTrue($body['ok']);
        self::assertSame(1, $body['processed']);
        self::assertSame(0, $body['failed']);
        self::assertGreaterThan(0, $body['run_id']);

        // Der Job muss jetzt status=done haben
        $stmt = $this->pdo()->prepare(
            "SELECT status FROM scheduled_jobs WHERE job_type = 'dialog_reminder' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute();
        self::assertSame('done', $stmt->fetchColumn());
    }

    // -------------------------------------------------------------------------
    // Rate-Limit
    // -------------------------------------------------------------------------

    public function test_rate_limit_blockt_nach_zu_vielen_versuchen(): void
    {
        $this->setupTokenHash();
        $this->enableNotifications();

        // Limit ist 6/60s. 6 Requests sollen durchgehen, der 7. wird geblockt.
        for ($i = 0; $i < 6; $i++) {
            $this->post('/cron/run', [], ['X-Cron-Token' => 'falsch']);
        }

        $response = $this->post('/cron/run', [], ['X-Cron-Token' => 'falsch']);
        self::assertSame(429, $response->getStatusCode());
        $body = json_decode($this->responseBody($response), true);
        self::assertSame('rate_limited', $body['reason'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function setupTokenHash(): void
    {
        $hash = hash('sha256', self::TOKEN);
        $this->setSetting('cron_external_token_hash', $hash);
    }

    private function enableNotifications(): void
    {
        $this->setSetting('notifications_enabled', 'true');
    }

    private function disableNotifications(): void
    {
        $this->setSetting('notifications_enabled', 'false');
    }

    private function setSetting(string $key, ?string $value): void
    {
        // INSERT ... ON DUPLICATE KEY UPDATE haelt es idempotent
        $this->pdo()->prepare(
            "INSERT INTO settings (setting_key, setting_value, setting_type)
             VALUES (:key, :value, 'string')
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute(['key' => $key, 'value' => $value]);
    }
}
