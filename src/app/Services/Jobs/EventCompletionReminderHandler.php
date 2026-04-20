<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Models\Event;
use App\Repositories\EventOrganizerRepository;
use App\Repositories\EventRepository;
use App\Repositories\UserRepository;
use App\Services\NotificationService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Erinnert Organisatoren daran, ein abgelaufenes Event abzuschliessen,
 * damit die Helferstunden fuer alle bestaetigten Helfer erzeugt werden.
 *
 * Payload: {"event_id": int}
 *
 * Skippt, wenn das Event bereits abgeschlossen oder abgesagt ist.
 */
final class EventCompletionReminderHandler implements JobHandler
{
    public function __construct(
        private readonly EventRepository $events,
        private readonly EventOrganizerRepository $organizers,
        private readonly UserRepository $users,
        private readonly NotificationService $notifications,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(array $payload): void
    {
        $eventId = (int) ($payload['event_id'] ?? 0);
        if ($eventId <= 0) {
            throw new RuntimeException('EventCompletionReminderHandler: event_id fehlt');
        }

        $event = $this->events->findById($eventId);
        if ($event === null) {
            $this->logger->info("EventCompletionReminderHandler: Event {$eventId} nicht vorhanden - skip");
            return;
        }

        if ($event->isFinal()) {
            $this->logger->info("EventCompletionReminderHandler: Event {$eventId} bereits im Status {$event->getStatus()} - skip");
            return;
        }

        $organizerIds = $this->organizers->listUserIdsForEvent($eventId);
        if ($organizerIds === []) {
            // Fallback: Ersteller benachrichtigen
            $organizerIds = [$event->getCreatedBy()];
        }

        $sent = 0;
        foreach ($organizerIds as $uid) {
            $user = $this->users->findById($uid);
            if ($user === null || $user->getEmail() === '') {
                continue;
            }
            $this->notifications->sendEventCompletionReminder(
                $user->getEmail(),
                $user->getVorname(),
                $event,
            );
            $sent++;
        }

        $this->logger->info("EventCompletionReminderHandler: Event {$eventId} - {$sent} Organisatoren benachrichtigt");
    }
}
