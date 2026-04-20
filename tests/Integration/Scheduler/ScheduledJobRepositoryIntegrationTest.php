<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use App\Repositories\ScheduledJobRepository;
use DateTimeImmutable;
use Tests\Support\IntegrationTestCase;

/**
 * Integrationstests fuer ScheduledJobRepository gegen echte MySQL-Testdatenbank.
 *
 * Voraussetzung: Migration 006 wurde in vaes + vaes_test eingespielt
 * (via create_database.sql oder Migration; danach 'php scripts/setup-test-db.php').
 */
class ScheduledJobRepositoryIntegrationTest extends IntegrationTestCase
{
    private ScheduledJobRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ScheduledJobRepository($this->pdo());
    }

    // -------------------------------------------------------------------------
    // insertIfNotExists
    // -------------------------------------------------------------------------

    /** @test */
    public function ohne_unique_key_wird_immer_neuer_job_eingefuegt(): void
    {
        $runAt = new DateTimeImmutable('+10 minutes');
        $id1 = $this->repo->insertIfNotExists('demo_job', null, ['x' => 1], $runAt);
        $id2 = $this->repo->insertIfNotExists('demo_job', null, ['x' => 2], $runAt);

        $this->assertGreaterThan(0, $id1);
        $this->assertNotSame($id1, $id2);
    }

    /** @test */
    public function mit_unique_key_dedupliziert_und_aktualisiert_run_at(): void
    {
        $early = new DateTimeImmutable('+10 minutes');
        $later = new DateTimeImmutable('+60 minutes');

        $id1 = $this->repo->insertIfNotExists('event_remind', 'event:42:24h', ['event_id' => 42], $early);
        $id2 = $this->repo->insertIfNotExists('event_remind', 'event:42:24h', ['event_id' => 42], $later);

        $this->assertSame($id1, $id2);

        $row = $this->repo->findById($id1);
        $this->assertNotNull($row);
        $this->assertStringContainsString($later->format('Y-m-d H:i'), (string) $row['run_at']);
    }

    /** @test */
    public function done_job_wird_bei_redispatch_mit_gleichem_key_reaktiviert(): void
    {
        $runAt = new DateTimeImmutable('+10 minutes');
        $id = $this->repo->insertIfNotExists('event_remind', 'event:7:24h', null, $runAt);

        $this->repo->markDone($id);
        $this->assertSame('done', $this->repo->findById($id)['status']);

        $this->repo->insertIfNotExists('event_remind', 'event:7:24h', null, new DateTimeImmutable('+20 minutes'));
        $row = $this->repo->findById($id);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertNull($row['last_error']);
    }

    // -------------------------------------------------------------------------
    // claimDue
    // -------------------------------------------------------------------------

    /** @test */
    public function claim_due_nimmt_nur_faellige_jobs(): void
    {
        $past = new DateTimeImmutable('-5 minutes');
        $future = new DateTimeImmutable('+5 minutes');

        $duePast = $this->repo->insertIfNotExists('demo', 'k-past', null, $past);
        $this->repo->insertIfNotExists('demo', 'k-future', null, $future);

        $claimed = $this->repo->claimDue(10);

        $this->assertCount(1, $claimed);
        $this->assertSame($duePast, (int) $claimed[0]['id']);
        $this->assertSame('running', $claimed[0]['status']);
        $this->assertSame(1, (int) $claimed[0]['attempts']);
    }

    /** @test */
    public function claim_due_respektiert_limit_und_sortiert_nach_run_at(): void
    {
        $this->repo->insertIfNotExists('demo', 'a', null, new DateTimeImmutable('-3 minutes'));
        $this->repo->insertIfNotExists('demo', 'b', null, new DateTimeImmutable('-5 minutes'));
        $this->repo->insertIfNotExists('demo', 'c', null, new DateTimeImmutable('-4 minutes'));

        $claimed = $this->repo->claimDue(2);

        $this->assertCount(2, $claimed);
        $this->assertSame('b', $claimed[0]['unique_key']);
        $this->assertSame('c', $claimed[1]['unique_key']);
    }

    // -------------------------------------------------------------------------
    // markDone / markFailed / Backoff
    // -------------------------------------------------------------------------

    /** @test */
    public function mark_done_setzt_status_und_finished_at(): void
    {
        $id = $this->repo->insertIfNotExists('demo', null, null, new DateTimeImmutable('-1 minute'));
        $this->repo->claimDue(10);
        $this->repo->markDone($id);

        $row = $this->repo->findById($id);
        $this->assertSame('done', $row['status']);
        $this->assertNotNull($row['finished_at']);
        $this->assertNull($row['last_error']);
    }

    /** @test */
    public function mark_failed_unterhalb_max_attempts_requeued_mit_backoff(): void
    {
        $id = $this->repo->insertIfNotExists('demo', null, null, new DateTimeImmutable('-1 minute'), maxAttempts: 3);
        $this->repo->claimDue(10);
        $this->repo->markFailed($id, 'smtp down');

        $row = $this->repo->findById($id);
        $this->assertSame('pending', $row['status']);
        $this->assertStringContainsString('smtp down', (string) $row['last_error']);
        $this->assertGreaterThan(time(), strtotime((string) $row['run_at']));
    }

    /** @test */
    public function mark_failed_bei_max_attempts_endgueltig_failed(): void
    {
        $id = $this->repo->insertIfNotExists('demo', null, null, new DateTimeImmutable('-1 minute'), maxAttempts: 1);
        $this->repo->claimDue(10);
        $this->repo->markFailed($id, 'final error');

        $row = $this->repo->findById($id);
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('final error', (string) $row['last_error']);
        $this->assertNotNull($row['finished_at']);
    }

    // -------------------------------------------------------------------------
    // cancel + requeueStuckJobs
    // -------------------------------------------------------------------------

    /** @test */
    public function cancel_by_unique_key_storniert_pending(): void
    {
        $this->repo->insertIfNotExists('demo', 'k1', null, new DateTimeImmutable('+10 minutes'));
        $affected = $this->repo->cancelByUniqueKey('k1');

        $this->assertSame(1, $affected);
        $row = $this->pdo()->query("SELECT status FROM scheduled_jobs WHERE unique_key='k1'")->fetch();
        $this->assertSame('cancelled', $row['status']);
    }

    /** @test */
    public function requeue_stuck_jobs_setzt_lange_laufende_zurueck(): void
    {
        $id = $this->repo->insertIfNotExists('demo', null, null, new DateTimeImmutable('-1 hour'));

        // Manuell in 'running' setzen mit altem started_at
        $this->pdo()->exec(
            "UPDATE scheduled_jobs SET status='running', started_at=DATE_SUB(NOW(), INTERVAL 20 MINUTE)
             WHERE id={$id}"
        );

        $requeued = $this->repo->requeueStuckJobs(stuckAfterMinutes: 15);
        $this->assertSame(1, $requeued);

        $row = $this->repo->findById($id);
        $this->assertSame('pending', $row['status']);
        $this->assertStringContainsString('stuck', (string) $row['last_error']);
    }

    /** @test */
    public function count_pending_zaehlt_nur_ueberfaellige(): void
    {
        $this->repo->insertIfNotExists('demo', 'p1', null, new DateTimeImmutable('-1 minute'));
        $this->repo->insertIfNotExists('demo', 'p2', null, new DateTimeImmutable('-2 minutes'));
        $this->repo->insertIfNotExists('demo', 'future', null, new DateTimeImmutable('+10 minutes'));

        $this->assertSame(2, $this->repo->countPending());
    }
}
