<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Models\Event;
use App\Repositories\EventRepository;
use App\Repositories\EventTaskAssignmentRepository;
use App\Repositories\UserRepository;
use App\Services\NotificationService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Verschickt Event-Reminder (24h oder 7d) an alle bestaetigten Helfer.
 *
 * Payload: {"event_id": int, "days_before": int}
 *
 * Derselbe Handler deckt beide Job-Typen ab (event_reminder_24h, event_reminder_7d);
 * die Registrierung in bootstrap.php / DI-Container legt fest, welchen Typ er bedient.
 */
final class EventReminderHandler implements JobHandler
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly EventTaskAssignmentRepository $assignments,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $eventId    = (int) ($payload['event_id'] ?? 0);
        $daysBefore = (int) ($payload['days_before'] ?? 1);

        if ($eventId <= 0) {
            throw new RuntimeException('EventReminderHandler: event_id fehlt');
        }

        $event = $this->events->findById($eventId);
        if ($event === null) {
            $this->logger->info("EventReminderHandler: Event {$eventId} nicht mehr vorhanden - skip");
            return;
        }

        if ($event->isFinal()) {
            $this->logger->info("EventReminderHandler: Event {$eventId} abgeschlossen/abgesagt - skip");
            return;
        }

        $sent = 0;
        foreach ($this->assignments->findConfirmedForEvent($eventId) as $assignment) {
            $user = $this->users->findById($assignment->getUserId());
            if ($user === null || $user->getEmail() === '') {
                continue;
            }
            $this->notifications->sendEventReminder(
                $user->getEmail(),
                $user->getVorname(),
                $event,
                $daysBefore,
            );
            $sent++;
        }

        $this->logger->info("EventReminderHandler: Event {$eventId} - {$sent} Erinnerungen verschickt ({$daysBefore}d)");
    }
}
