<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskAssignment;
use App\Repositories\EventRepository;
use App\Repositories\EventTaskAssignmentRepository;
use App\Repositories\EventTaskRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkEntryRepository;
use PDO;

/**
 * Event-Abschluss-Orchestrator (Modul 6 I3).
 *
 * Kapselt den atomaren Abschluss eines Events:
 *  1. Event-Status  veroeffentlicht -> abgeschlossen
 *  2. Fuer jede bestaetigte Zusage:
 *     a) work_entry erzeugen (status=eingereicht, origin=event,
 *        created_by_user_id=SYSTEM, event_task_assignment_id=FK)
 *     b) Assignment-Status bestaetigt -> abgeschlossen + work_entry_id-FK
 *  3. Audit-Events schreiben
 *
 * Alles innerhalb EINER PDO-Transaktion. Schlaegt irgendein Schritt fehl:
 * kompletter Rollback, Event bleibt veroeffentlicht, keine work_entries.
 *
 * Self-Approval-Guard greift SPAETER automatisch via WorkflowService::
 * approve(), weil work_entries.user_id = assignment.user_id und
 * WorkflowService::canReviewerAct() prueft reviewer.id === entry.user_id.
 */
final class EventCompletionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly EventRepository $eventRepo,
        private readonly EventTaskRepository $taskRepo,
        private readonly EventTaskAssignmentRepository $assignmentRepo,
        private readonly WorkEntryRepository $workEntryRepo,
        private readonly UserRepository $userRepo,
        private readonly AuditService $audit
    ) {
    }

    /**
     * Event abschliessen + work_entries fuer alle bestaetigten Zusagen anlegen.
     *
     * @return array{assignments_processed:int, work_entries_created:int, skipped:int}
     * @throws BusinessRuleException
     */
    public function completeEvent(int $eventId, int $actorUserId): array
    {
        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            throw new BusinessRuleException('Event nicht gefunden.');
        }
        if ($event->getStatus() !== Event::STATUS_VEROEFFENTLICHT) {
            throw new BusinessRuleException(
                'Nur veroeffentlichte Events koennen abgeschlossen werden.'
            );
        }

        $systemUserId = $this->userRepo->getSystemUserId();
        $confirmedAssignments = $this->assignmentRepo->findConfirmedForEvent($eventId);

        $this->pdo->beginTransaction();
        try {
            $processed = 0;
            $created = 0;
            $skipped = 0;

            foreach ($confirmedAssignments as $a) {
                $task = $this->taskRepo->findById($a->getTaskId());
                if ($task === null) {
                    $skipped++;
                    continue; // Task soft-deleted zwischen Query und Loop
                }

                $workEntryId = $this->createWorkEntryForAssignment(
                    $event,
                    $task,
                    $a,
                    $systemUserId,
                    $actorUserId
                );

                $this->assignmentRepo->setWorkEntryId($a->getId(), $workEntryId);
                $this->assignmentRepo->changeStatus(
                    $a->getId(),
                    EventTaskAssignment::STATUS_ABGESCHLOSSEN
                );

                $this->audit->log(
                    action: 'status_change',
                    tableName: 'event_task_assignments',
                    recordId: $a->getId(),
                    oldValues: ['status' => EventTaskAssignment::STATUS_BESTAETIGT],
                    newValues: ['status' => EventTaskAssignment::STATUS_ABGESCHLOSSEN],
                    description: 'Assignment bei Event-Abschluss finalisiert',
                    metadata: [
                        'work_entry_id' => $workEntryId,
                        'event_id' => $eventId,
                    ]
                );

                $processed++;
                $created++;
            }

            $this->eventRepo->changeStatus($eventId, Event::STATUS_ABGESCHLOSSEN);

            $this->audit->log(
                action: 'status_change',
                tableName: 'events',
                recordId: $eventId,
                oldValues: ['status' => Event::STATUS_VEROEFFENTLICHT],
                newValues: ['status' => Event::STATUS_ABGESCHLOSSEN],
                description: 'Event abgeschlossen (Auto-Generate work_entries)',
                metadata: [
                    'assignments_processed' => $processed,
                    'work_entries_created' => $created,
                    'assignments_skipped' => $skipped,
                    'actor_user_id' => $actorUserId,
                ]
            );

            $this->pdo->commit();

            return [
                'assignments_processed' => $processed,
                'work_entries_created' => $created,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Berechnet Stunden, Zeitraum, Beschreibung und erzeugt den work_entry.
     * Schreibt den create-Audit.
     */
    private function createWorkEntryForAssignment(
        Event $event,
        EventTask $task,
        EventTaskAssignment $assignment,
        int $systemUserId,
        int $actorUserId
    ): int {
        // E2: hours je nach Slot-Mode
        $hours = $this->computeHours($task, $assignment);

        // Zeitraum: bei fix aus Task, bei variabel aus proposed
        $timeFrom = null;
        $timeTo   = null;
        $workDate = substr($event->getStartAt(), 0, 10); // YYYY-MM-DD

        if ($task->hasFixedSlot() && $task->getStartAt() !== null) {
            $timeFrom = substr($task->getStartAt(), 11, 5); // HH:MM
            $timeTo   = $task->getEndAt() !== null ? substr($task->getEndAt(), 11, 5) : null;
            $workDate = substr($task->getStartAt(), 0, 10);
        } elseif ($assignment->getProposedStart() !== null) {
            $timeFrom = substr($assignment->getProposedStart(), 11, 5);
            $timeTo   = $assignment->getProposedEnd() !== null
                ? substr($assignment->getProposedEnd(), 11, 5) : null;
            $workDate = substr($assignment->getProposedStart(), 0, 10);
        }

        $description = sprintf(
            'Automatisch erzeugt aus Event "%s" / Aufgabe "%s"',
            $event->getTitle(),
            $task->getTitle()
        );

        $workEntryId = $this->workEntryRepo->create([
            'user_id' => $assignment->getUserId(),
            'created_by_user_id' => $systemUserId,
            'category_id' => $task->getCategoryId(),
            'work_date' => $workDate,
            'time_from' => $timeFrom,
            'time_to' => $timeTo,
            'hours' => $hours,
            'description' => $description,
            'project' => $event->getTitle(),
            'status' => 'eingereicht',
            'origin' => 'event',
            'event_task_assignment_id' => $assignment->getId(),
        ]);

        $this->audit->log(
            action: 'create',
            tableName: 'work_entries',
            recordId: $workEntryId,
            newValues: [
                'status' => 'eingereicht',
                'user_id' => $assignment->getUserId(),
                'hours' => $hours,
            ],
            description: "Helferstunden-Antrag aus Event '{$event->getTitle()}' generiert",
            metadata: [
                'origin' => 'event',
                'event_id' => $event->getId(),
                'event_task_assignment_id' => $assignment->getId(),
                'task_id' => $task->getId(),
                'actor_user_id' => $actorUserId,
            ]
        );

        return $workEntryId;
    }

    /**
     * E2: Stunden-Berechnung.
     *  - actual_hours aus Assignment hat Prioritaet, falls gesetzt
     *  - slot_mode=fix: task.hours_default
     *  - slot_mode=variabel: proposed_end - proposed_start, oder Fallback hours_default
     */
    private function computeHours(EventTask $task, EventTaskAssignment $a): float
    {
        $actual = $a->getActualHours();
        if ($actual !== null && $actual > 0) {
            return $actual;
        }

        if ($task->hasFixedSlot()) {
            return $task->getHoursDefault();
        }

        if ($a->getProposedStart() !== null && $a->getProposedEnd() !== null) {
            $start = strtotime($a->getProposedStart());
            $end   = strtotime($a->getProposedEnd());
            if ($start !== false && $end !== false && $end > $start) {
                return round(($end - $start) / 3600, 2);
            }
        }

        return $task->getHoursDefault();
    }
}
