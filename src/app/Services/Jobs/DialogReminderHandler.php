<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Repositories\DialogRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkEntryRepository;
use App\Services\NotificationService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Erinnert Antragsteller an eine unbeantwortete Rueckfrage.
 *
 * Payload: {"work_entry_id": int, "days_open": int}
 *
 * Wenn die Rueckfrage inzwischen beantwortet oder der Antrag archiviert wurde,
 * wird der Job still geskippt (kein Retry).
 */
final class DialogReminderHandler implements JobHandler
{
    public function __construct(
        private readonly WorkEntryRepository $entries,
        private readonly DialogRepository $dialogs,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $entryId   = (int) ($payload['work_entry_id'] ?? 0);
        $daysOpen  = (int) ($payload['days_open'] ?? 3);

        if ($entryId <= 0) {
            throw new RuntimeException('DialogReminderHandler: work_entry_id fehlt');
        }

        $entry = $this->entries->findById($entryId);
        if ($entry === null) {
            $this->logger->info("DialogReminderHandler: Entry {$entryId} nicht vorhanden - skip");
            return;
        }

        if ($this->dialogs->countOpenQuestions($entryId) === 0) {
            $this->logger->info("DialogReminderHandler: Entry {$entryId} keine offenen Fragen - skip");
            return;
        }

        $owner = $this->users->findById($entry->getUserId());
        if ($owner === null || $owner->getEmail() === '') {
            return;
        }

        $this->notifications->sendDialogReminder(
            $owner->getEmail(),
            $owner->getVorname(),
            $entry->getEntryNumber(),
            $entryId,
            $daysOpen,
        );

        $this->logger->info("DialogReminderHandler: Entry {$entryId} - Reminder an {$owner->getEmail()} verschickt");
    }
}
