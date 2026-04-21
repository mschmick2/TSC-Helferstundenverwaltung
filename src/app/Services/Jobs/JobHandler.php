<?php

declare(strict_types=1);

namespace App\Services\Jobs;

/**
 * Jeder Job-Handler implementiert dieses Interface.
 *
 * Kontrakt:
 *   - handle() wirft Exception bei Fehler → SchedulerService retried.
 *   - handle() kehrt ohne Exception zurueck → Job = 'done'.
 *   - Handler sollen idempotent sein (Retries duerfen keine Doppel-Mails senden,
 *     wo moeglich; sonst muss das im Handler dokumentiert sein).
 */
interface JobHandler
{
    /**
     * @param array<string, mixed> $payload Dekodierter payload aus scheduled_jobs.payload
     */
    public function handle(array $payload): void;
}
