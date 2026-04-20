<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use App\Repositories\SchedulerRunRepository;
use Tests\Support\IntegrationTestCase;

class SchedulerRunRepositoryIntegrationTest extends IntegrationTestCase
{
    private SchedulerRunRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SchedulerRunRepository($this->pdo());
    }

    /** @test */
    public function start_erzeugt_run_mit_trigger_source(): void
    {
        $id = $this->repo->start('external', '127.0.0.1');
        $this->assertGreaterThan(0, $id);

        $row = $this->pdo()
            ->query("SELECT trigger_source, ip_address, finished_at FROM scheduler_runs WHERE id={$id}")
            ->fetch();
        $this->assertSame('external', $row['trigger_source']);
        $this->assertSame('127.0.0.1', $row['ip_address']);
        $this->assertNull($row['finished_at']);
    }

    /** @test */
    public function finish_setzt_counter_und_timestamp(): void
    {
        $id = $this->repo->start('request', null);
        $this->repo->finish($id, jobsProcessed: 7, jobsFailed: 2);

        $row = $this->pdo()
            ->query("SELECT jobs_processed, jobs_failed, finished_at FROM scheduler_runs WHERE id={$id}")
            ->fetch();
        $this->assertSame(7, (int) $row['jobs_processed']);
        $this->assertSame(2, (int) $row['jobs_failed']);
        $this->assertNotNull($row['finished_at']);
    }

    /** @test */
    public function list_recent_ordnet_nach_started_at_desc(): void
    {
        $this->repo->start('manual', null);
        $this->repo->start('external', '1.2.3.4');
        $this->repo->start('request', null);

        $rows = $this->repo->listRecent(10);
        $this->assertGreaterThanOrEqual(3, count($rows));

        // Neuester zuerst
        $this->assertSame('request', $rows[0]['trigger_source']);
    }
}
