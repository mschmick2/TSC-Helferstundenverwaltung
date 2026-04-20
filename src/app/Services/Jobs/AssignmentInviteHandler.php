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
 * Benachrichtigt einen Helfer direkt nach der Zusage-Erstellung.
 *
 * Payload: {"assignment_id": int}
 *
 * Laeuft sofort (run_at = now), damit die E-Mail nicht den Request blockiert.
 */
final class AssignmentInviteHandler implements JobHandler
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
            throw new RuntimeException('AssignmentInviteHandler: assignment_id fehlt');
        }

        $assignment = $this->assignments->findById($assignmentId);
        if ($assignment === null) {
            $this->logger->info("AssignmentInviteHandler: Assignment {$assignmentId} nicht gefunden - skip");
            return;
        }

        // Nur Einladungen fuer noch offene Zusagen
        if ($assignment->getStatus() !== EventTaskAssignment::STATUS_VORGESCHLAGEN) {
            $this->logger->info("AssignmentInviteHandler: Assignment {$assignmentId} bereits bearbeitet - skip");
            return;
        }

        $task  = $this->tasks->findById($assignment->getTaskId());
        $user  = $this->users->findById($assignment->getUserId());
        if ($task === null || $user === null || $user->getEmail() === '') {
            $this->logger->warning("AssignmentInviteHandler: Task/User fehlt fuer Assignment {$assignmentId}");
            return;
        }

        $event = $this->events->findById($task->getEventId());
        if ($event === null) {
            $this->logger->warning("AssignmentInviteHandler: Event fehlt fuer Task {$task->getId()}");
            return;
        }

        $this->notifications->sendAssignmentInvite(
            $user->getEmail(),
            $user->getVorname(),
            $event,
            $task,
        );

        $this->logger->info("AssignmentInviteHandler: Assignment {$assignmentId} - Invite an {$user->getEmail()} verschickt");
    }
}
