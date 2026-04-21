<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessRuleException;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskAssignment;
use App\Repositories\EventOrganizerRepository;
use App\Repositories\EventRepository;
use App\Repositories\EventTaskAssignmentRepository;
use App\Repositories\EventTaskRepository;
use App\Repositories\UserRepository;
use DateInterval;
use DateTimeImmutable;

/**
 * Kern-Service fuer die Event-Assignment-Geschaeftslogik (Modul 6 I2).
 *
 * Verantwortungen:
 *  - Aufgabenuebernahme (Capacity-Check, Slot-Mode-Logik)
 *  - Storno-Workflow (Deadline-Check, Ersatz-Vorschlag)
 *  - Organisator-Entscheidungen (Zeitfenster + Storno freigeben/ablehnen)
 *  - Selbstgenehmigungs-Guards (kein Organisator entscheidet eigene Zusage)
 *
 * Alle Methoden werfen `BusinessRuleException` oder `AuthorizationException`
 * bei Regelverletzungen und schreiben einen `AuditService::log()`-Eintrag
 * bei erfolgreichen Aktionen.
 */
final class EventAssignmentService
{
    public function __construct(
        private readonly EventRepository $eventRepo,
        private readonly EventTaskRepository $taskRepo,
        private readonly EventTaskAssignmentRepository $assignmentRepo,
        private readonly EventOrganizerRepository $organizerRepo,
        private readonly AuditService $audit,
        private readonly ?UserRepository $userRepo = null,
        private readonly ?SchedulerService $scheduler = null
    ) {
    }

    // =========================================================================
    // Mitglieder-Operationen
    // =========================================================================

    /**
     * Mitglied uebernimmt eine Aufgabe.
     *
     * Bei slot_mode='fix':       Status = bestaetigt (Task-Zeiten werden uebernommen)
     * Bei slot_mode='variabel':  Status = vorgeschlagen (Organisator muss pruefen)
     *
     * @throws BusinessRuleException
     */
    public function assignMember(
        int $taskId,
        int $userId,
        ?string $proposedStart = null,
        ?string $proposedEnd = null
    ): EventTaskAssignment {
        $task = $this->taskRepo->findById($taskId);
        if ($task === null) {
            throw new BusinessRuleException('Aufgabe nicht gefunden oder geloescht.');
        }

        $event = $this->eventRepo->findById($task->getEventId());
        if ($event === null || !$event->isPublished()) {
            throw new BusinessRuleException(
                'Event ist nicht veroeffentlicht oder bereits abgeschlossen.'
            );
        }

        // Doppelbuchung pro User ausschliessen (aktive Zusage zaehlt)
        if ($this->assignmentRepo->hasActiveAssignment($taskId, $userId)) {
            throw new BusinessRuleException('Du hast diese Aufgabe bereits uebernommen.');
        }

        // Capacity-Check (hart fuer 'maximum')
        $this->assertCapacityAllows($task);

        // Slot-Mode-Logik
        if ($task->hasFixedSlot()) {
            // Fixer Slot: Mitglied kann KEINEN abweichenden Vorschlag machen
            $proposedStart = null;
            $proposedEnd = null;
            $status = EventTaskAssignment::STATUS_BESTAETIGT;
        } else {
            // Variabel: Vorschlag ist Pflicht
            if ($proposedStart === null || $proposedEnd === null) {
                throw new BusinessRuleException(
                    'Bei variablem Zeitfenster muss Start und Ende vorgeschlagen werden.'
                );
            }
            if (strtotime($proposedEnd) <= strtotime($proposedStart)) {
                throw new BusinessRuleException('Ende muss nach Start liegen.');
            }
            $status = EventTaskAssignment::STATUS_VORGESCHLAGEN;
        }

        $assignmentId = $this->assignmentRepo->create([
            'task_id' => $taskId,
            'user_id' => $userId,
            'status' => $status,
            'proposed_start' => $proposedStart,
            'proposed_end' => $proposedEnd,
        ]);

        $this->audit->log(
            action: 'create',
            tableName: 'event_task_assignments',
            recordId: $assignmentId,
            newValues: ['task_id' => $taskId, 'status' => $status],
            description: $task->hasFixedSlot()
                ? "Aufgabe '{$task->getTitle()}' uebernommen (fix)"
                : "Zeitfenster fuer '{$task->getTitle()}' vorgeschlagen",
            metadata: [
                'event_id' => $task->getEventId(),
                'slot_mode' => $task->getSlotMode(),
                'hours_default' => $task->getHoursDefault(),
            ]
        );

        $assignment = $this->assignmentRepo->findById($assignmentId);
        if ($assignment === null) {
            // Defensive: sollte nie passieren, wir haben gerade inserted
            throw new \RuntimeException('Zusage nach Insert nicht auffindbar.');
        }

        // Bei VORGESCHLAGEN: Helfer per Invite informieren (sofort) und in 48h
        // erinnern, falls keine Bestaetigung erfolgt. Bei BESTAETIGT brauchen
        // wir keinen Invite — der Helfer hat die Aufgabe selbst aktiv genommen.
        if ($status === EventTaskAssignment::STATUS_VORGESCHLAGEN) {
            $this->dispatchAssignmentInvite($assignmentId);
        }

        return $assignment;
    }

    /**
     * Mitglied zieht eigene, noch unbestaetigte Zusage zurueck.
     * Nur fuer Status = vorgeschlagen. Bestaetigte Zusagen gehen ueber
     * requestCancellation() mit Organisator-Freigabe (§5.5 Requirements).
     *
     * @throws AuthorizationException|BusinessRuleException
     */
    public function withdrawSelf(int $assignmentId, int $userId): void
    {
        $a = $this->assignmentRepo->findById($assignmentId);
        if ($a === null) {
            throw new BusinessRuleException('Zusage nicht gefunden.');
        }
        if ($a->getUserId() !== $userId) {
            throw new AuthorizationException('Nur eigene Zusagen koennen zurueckgezogen werden.');
        }
        if ($a->getStatus() !== EventTaskAssignment::STATUS_VORGESCHLAGEN) {
            throw new BusinessRuleException(
                'Bestaetigte Zusagen koennen nur ueber Storno zurueckgezogen werden.'
            );
        }

        $this->assignmentRepo->softDelete($assignmentId, $userId);

        $this->audit->log(
            action: 'delete',
            tableName: 'event_task_assignments',
            recordId: $assignmentId,
            description: 'Zusage zurueckgezogen (unbestaetigt)',
            metadata: ['task_id' => $a->getTaskId()]
        );

        $this->cancelAssignmentJobs($assignmentId);
    }

    /**
     * Mitglied stellt Storno-Anfrage fuer bestaetigte Zusage.
     *
     * Deadline-Check: Ab `task.start_at - event.cancel_deadline_hours`
     * ist Mitglieder-seitig kein Storno mehr moeglich (Organisator-Override
     * via approveCancellation mit Notiz).
     *
     * @throws BusinessRuleException
     */
    public function requestCancellation(
        int $assignmentId,
        int $userId,
        ?int $replacementUserId = null,
        ?string $reason = null
    ): void {
        $a = $this->assignmentRepo->findById($assignmentId);
        if ($a === null || $a->getUserId() !== $userId) {
            throw new AuthorizationException('Nur eigene Zusagen stornierbar.');
        }
        if ($a->getStatus() !== EventTaskAssignment::STATUS_BESTAETIGT) {
            throw new BusinessRuleException(
                'Storno nur fuer bestaetigte Zusagen moeglich.'
            );
        }

        $task = $this->taskRepo->findById($a->getTaskId());
        $event = $task !== null ? $this->eventRepo->findById($task->getEventId()) : null;
        if ($task === null || $event === null) {
            throw new BusinessRuleException('Event/Aufgabe nicht auffindbar.');
        }

        $this->assertDeadlineNotPassed($task, $event);

        // S1: Replacement-User-Existenz-Check (wenn UserRepo injiziert).
        // Verhindert FK-Violation + liefert saubere Fehlermeldung statt 500.
        if ($replacementUserId !== null && $this->userRepo !== null) {
            $replacementUser = $this->userRepo->findById($replacementUserId);
            if ($replacementUser === null) {
                throw new BusinessRuleException('Vorgeschlagener Ersatz existiert nicht.');
            }
            if (!$replacementUser->isActive()) {
                throw new BusinessRuleException(
                    'Vorgeschlagener Ersatz ist nicht aktiv. Bitte anderes Mitglied waehlen.'
                );
            }
            if ($replacementUser->getId() === $userId) {
                throw new BusinessRuleException(
                    'Du kannst dich nicht selbst als Ersatz vorschlagen.'
                );
            }
        }

        $this->assignmentRepo->setReplacement($assignmentId, $replacementUserId);
        $this->assignmentRepo->changeStatus(
            $assignmentId,
            EventTaskAssignment::STATUS_STORNO_ANGEFRAGT
        );

        $this->audit->log(
            action: 'status_change',
            tableName: 'event_task_assignments',
            recordId: $assignmentId,
            oldValues: ['status' => EventTaskAssignment::STATUS_BESTAETIGT],
            newValues: ['status' => EventTaskAssignment::STATUS_STORNO_ANGEFRAGT],
            description: 'Storno angefragt',
            metadata: [
                'task_id' => $a->getTaskId(),
                'event_id' => $task->getEventId(),
                'replacement_user_id' => $replacementUserId,
                'reason' => $reason,
            ]
        );
    }

    // =========================================================================
    // Organisator-Operationen (mit Self-Approval-Guards)
    // =========================================================================

    /**
     * Organisator bestaetigt einen variablen Zeitfenster-Vorschlag.
     *
     * Self-Approval-Guard: Wenn Organisator die eigene Zusage bestaetigen
     * wuerde, wirft `AuthorizationException` — zweiter Organisator oder
     * Eventadmin muss entscheiden (Requirements §12.1).
     *
     * @throws AuthorizationException|BusinessRuleException
     */
    public function approveTime(int $assignmentId, int $organizerId): void
    {
        [$a, $task, $event] = $this->loadAssignmentContext($assignmentId);

        $this->assertIsEventOrganizer($event, $organizerId);
        $this->assertNoSelfApproval($a, $organizerId);

        if ($a->getStatus() !== EventTaskAssignment::STATUS_VORGESCHLAGEN) {
            throw new BusinessRuleException(
                'Nur vorgeschlagene Zeitfenster koennen bestaetigt werden.'
            );
        }

        $this->assignmentRepo->changeStatus(
            $assignmentId,
            EventTaskAssignment::STATUS_BESTAETIGT
        );

        $this->audit->log(
            action: 'status_change',
            tableName: 'event_task_assignments',
            recordId: $assignmentId,
            oldValues: ['status' => EventTaskAssignment::STATUS_VORGESCHLAGEN],
            newValues: ['status' => EventTaskAssignment::STATUS_BESTAETIGT],
            description: 'Zeitfenster vom Organisator bestaetigt',
            metadata: [
                'task_id' => $a->getTaskId(),
                'event_id' => $event->getId(),
                'approver_id' => $organizerId,
            ]
        );

        // Bestaetigte Zusage braucht keinen Reminder mehr.
        $this->cancelAssignmentJobs($assignmentId);
    }

    /**
     * Organisator lehnt variablen Vorschlag mit Begruendung ab.
     * Status geht zurueck auf storniert (Mitglied kann neu vorschlagen
     * via neuer Assignment).
     *
     * @throws AuthorizationException|BusinessRuleException
     */
    public function rejectTime(int $assignmentId, int $organizerId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new BusinessRuleException('Ablehnung erfordert Begruendung.');
        }

        [$a, $task, $event] = $this->loadAssignmentContext($assignmentId);

        $this->assertIsEventOrganizer($event, $organizerId);
        $this->assertNoSelfApproval($a, $organizerId);

        if ($a->getStatus() !== EventTaskAssignment::STATUS_VORGESCHLAGEN) {
            throw new BusinessRuleException(
                'Nur vorgeschlagene Zeitfenster koennen abgelehnt werden.'
            );
        }

        $this->assignmentRepo->changeStatus(
            $assignmentId,
            EventTaskAssignment::STATUS_STORNIERT
        );

        $this->audit->log(
            action: 'status_change',
            tableName: 'event_task_assignments',
            recordId: $assignmentId,
            oldValues: ['status' => EventTaskAssignment::STATUS_VORGESCHLAGEN],
            newValues: ['status' => EventTaskAssignment::STATUS_STORNIERT],
            description: 'Zeitfenster-Vorschlag abgelehnt',
            metadata: [
                'task_id' => $a->getTaskId(),
                'event_id' => $event->getId(),
                'reason' => $reason,
                'rejecter_id' => $organizerId,
            ]
        );

        $this->cancelAssignmentJobs($assignmentId);
    }

    /**
     * Organisator bestaetigt Storno-Anfrage eines Mitglieds.
     *
     * @throws AuthorizationException|BusinessRuleException
     */
    public function approveCancellation(int $assignmentId, int $organizerId): void
    {
        [$a, $task, $event] = $this->loadAssignmentContext($assignmentId);

        $this->assertIsEventOrganizer($event, $organizerId);
        $this->assertNoSelfApproval($a, $organizerId);

        if ($a->getStatus() !== EventTaskAssignment::STATUS_STORNO_ANGEFRAGT) {
            throw new BusinessRuleException(
                'Nur angefragte Stornos koennen bestaetigt werden.'
            );
        }

        $this->assignmentRepo->changeStatus(
            $assignmentId,
            EventTaskAssignment::STATUS_STORNIERT
        );

        $this->audit->log(
            action: 'status_change',
            tableName: 'event_task_assignments',
            recordId: $assignmentId,
            oldValues: ['status' => EventTaskAssignment::STATUS_STORNO_ANGEFRAGT],
            newValues: ['status' => EventTaskAssignment::STATUS_STORNIERT],
            description: 'Storno vom Organisator bestaetigt',
            metadata: [
                'task_id' => $a->getTaskId(),
                'event_id' => $event->getId(),
                'approver_id' => $organizerId,
                'replacement_user_id' => $a->getReplacementSuggestedUserId(),
            ]
        );

        $this->cancelAssignmentJobs($assignmentId);
    }

    /**
     * Organisator lehnt Storno ab (z.B. weil Mitglied die Zusage halten soll).
     * Status geht zurueck auf bestaetigt.
     *
     * @throws AuthorizationException|BusinessRuleException
     */
    public function rejectCancellation(int $assignmentId, int $organizerId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new BusinessRuleException('Ablehnung erfordert Begruendung.');
        }

        [$a, $task, $event] = $this->loadAssignmentContext($assignmentId);

        $this->assertIsEventOrganizer($event, $organizerId);
        $this->assertNoSelfApproval($a, $organizerId);

        if ($a->getStatus() !== EventTaskAssignment::STATUS_STORNO_ANGEFRAGT) {
            throw new BusinessRuleException(
                'Nur angefragte Stornos koennen abgelehnt werden.'
            );
        }

        $this->assignmentRepo->changeStatus(
            $assignmentId,
            EventTaskAssignment::STATUS_BESTAETIGT
        );

        $this->audit->log(
            action: 'status_change',
            tableName: 'event_task_assignments',
            recordId: $assignmentId,
            oldValues: ['status' => EventTaskAssignment::STATUS_STORNO_ANGEFRAGT],
            newValues: ['status' => EventTaskAssignment::STATUS_BESTAETIGT],
            description: 'Storno-Anfrage abgelehnt',
            metadata: [
                'task_id' => $a->getTaskId(),
                'event_id' => $event->getId(),
                'reason' => $reason,
                'rejecter_id' => $organizerId,
            ]
        );
    }

    // =========================================================================
    // Guards (reusable)
    // =========================================================================

    /**
     * @return array{0: EventTaskAssignment, 1: EventTask, 2: Event}
     */
    private function loadAssignmentContext(int $assignmentId): array
    {
        $a = $this->assignmentRepo->findById($assignmentId);
        if ($a === null) {
            throw new BusinessRuleException('Zusage nicht gefunden.');
        }
        $task = $this->taskRepo->findById($a->getTaskId());
        if ($task === null) {
            throw new BusinessRuleException('Aufgabe nicht auffindbar.');
        }
        $event = $this->eventRepo->findById($task->getEventId());
        if ($event === null) {
            throw new BusinessRuleException('Event nicht auffindbar.');
        }
        return [$a, $task, $event];
    }

    private function assertIsEventOrganizer(Event $event, int $organizerId): void
    {
        if (!$this->organizerRepo->isOrganizer((int) $event->getId(), $organizerId)) {
            throw new AuthorizationException(
                'Nur Event-Organisatoren koennen diese Aktion ausfuehren.'
            );
        }
    }

    /**
     * Requirements §12.1: Organisator darf eigene Zusagen nicht selbst
     * freigeben/ablehnen/storno-entscheiden. Zweiter Organisator oder
     * Eventadmin muss ran.
     */
    private function assertNoSelfApproval(EventTaskAssignment $a, int $organizerId): void
    {
        if ($a->getUserId() === $organizerId) {
            throw new BusinessRuleException(
                'Eigene Zusagen koennen nicht selbst freigegeben werden. '
                . 'Ein anderer Organisator oder ein Eventadmin muss entscheiden.'
            );
        }
    }

    private function assertCapacityAllows(EventTask $task): void
    {
        if ($task->getCapacityMode() !== EventTask::CAP_MAXIMUM) {
            return; // unbegrenzt + ziel lassen Eintragungen zu
        }
        $current = $this->assignmentRepo->countActiveByTask((int) $task->getId());
        if ($current >= (int) $task->getCapacityTarget()) {
            throw new BusinessRuleException(
                'Die maximale Anzahl Helfer fuer diese Aufgabe ist erreicht.'
            );
        }
    }

    private function assertDeadlineNotPassed(EventTask $task, Event $event): void
    {
        $taskStart = $task->hasFixedSlot() ? $task->getStartAt() : $event->getStartAt();
        if ($taskStart === null) {
            return; // kein Zeitanker -> Deadline nicht pruefbar
        }

        $deadlineHours = $event->getCancelDeadlineHours() ?? Event::DEFAULT_CANCEL_DEADLINE_HOURS;
        $startTs = strtotime($taskStart);
        if ($startTs === false) {
            return;
        }
        $deadlineTs = $startTs - ($deadlineHours * 3600);
        if (time() > $deadlineTs) {
            throw new BusinessRuleException(
                "Storno-Deadline ({$deadlineHours}h vor Start) ist abgelaufen. "
                . 'Bitte den Organisator direkt kontaktieren.'
            );
        }
    }

    // =========================================================================
    // Scheduler-Hooks (Notifications/Reminder)
    // =========================================================================

    /**
     * Plant Invite (jetzt) und Reminder (in 48h) fuer eine VORGESCHLAGEN-Zusage.
     *
     * - Invite: `dispatchIfNew()` — einmalige Einladungsmail, wird nicht
     *   nochmal verschickt, wenn der Assignment-Status hin-und-her-kippt
     *   (VORGESCHLAGEN -> ABGELEHNT -> wieder VORGESCHLAGEN).
     * - Reminder: normaler `dispatch()` — darf bei Event-Verschiebung
     *   neu eingeplant werden.
     */
    private function dispatchAssignmentInvite(int $assignmentId): void
    {
        if ($this->scheduler === null) {
            return;
        }

        $now = new DateTimeImmutable();
        $payload = ['assignment_id' => $assignmentId];

        $this->scheduler->dispatchIfNew(
            'assignment_invite',
            $payload,
            $now,
            "assignment:{$assignmentId}:invite"
        );

        $reminderAt = $now->add(new DateInterval('PT48H'));
        $this->scheduler->dispatch(
            'assignment_reminder',
            $payload,
            $reminderAt,
            "assignment:{$assignmentId}:reminder"
        );
    }

    /**
     * Storniert pending Invite/Reminder einer Zusage. Idempotent (UPDATE).
     */
    private function cancelAssignmentJobs(int $assignmentId): void
    {
        if ($this->scheduler === null) {
            return;
        }
        $this->scheduler->cancel("assignment:{$assignmentId}:invite");
        $this->scheduler->cancel("assignment:{$assignmentId}:reminder");
    }
}
