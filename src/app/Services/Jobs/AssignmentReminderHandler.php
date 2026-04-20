<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Models\EventTaskAssignment;
use App\Repositories\EventRepository;
use App\Repositories\EventTaskAssignmentRepository;
use App\Repositories\EventTaskRepository;
use App\Repositories\UserRepository;
use App\Services\NotificationService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Erinnert Helfer an eine noch nicht bestaetigte Zusage.
 *
 * Payload: {"assignment_id": int}
 *
 * Wird nach dem Invite (typ. 48h spaeter) geplant - bei bestaetigten
 * oder zurueckgezogenen Zusagen still geskippt.
 */
final class AssignmentReminderHandler implements JobHandler
{
    public function __construct(
        private readonly EventTaskAssignmentRepository $assignments,
        private readonly EventTaskRepository $tasks,
        private readonly EventRepository $events,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $assignmentId = (int) ($payload['assignment_id'] ?? 0);
        if ($assignmentId <= 0) {
            throw new RuntimeException('AssignmentReminderHandler: assignment_id fehlt');
        }

        $assignment = $this->assignments->findById($assignmentId);
        if ($assignment === null) {
            return;
        }

        // Nur wenn noch im Vorschlags-Status
        if ($assignment->getStatus() !== EventTaskAssignment::STATUS_VORGESCHLAGEN) {
            $this->logger->info("AssignmentReminderHandler: Assignment {$assignmentId} bereits im Status {$assignment->getStatus()} - skip");
            return;
        }

        $task  = $this->tasks->findById($assignment->getTaskId());
        $user  = $this->users->findById($assignment->getUserId());
        if ($task === null || $user === null || $user->getEmail() === '') {
            return;
        }

        $event = $this->events->findById($task->getEventId());
        if ($event === null || $event->isFinal()) {
            return;
        }

        $this->notifications->sendAssignmentReminder(
            $user->getEmail(),
            $user->getVorname(),
            $event,
            $task,
        );

        $this->logger->info("AssignmentReminderHandler: Assignment {$assignmentId} - Reminder an {$user->getEmail()} verschickt");
    }
}
