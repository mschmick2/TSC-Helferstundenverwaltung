<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessRuleException;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskAssignment;
use App\Repositories\EventOrganizerRepository;
use App\Repositories\EventRepository;
use App\Repositories\EventTaskAssignmentRepository;
use App\Repositories\EventTaskRepository;
use App\Services\AuditService;
use App\Services\EventAssignmentService;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer die Assignment-Geschaeftslogik.
 *
 * Fokus: Self-Approval-Guards, Capacity-Check, Status-Uebergaenge.
 * Mocks via Closure-Anonymous-Klassen - kein echter DB-Zugriff.
 */
final class EventAssignmentServiceTest extends TestCase
{
    public function test_approveTime_blocks_self_approval(): void
    {
        $organizerId = 42;
        $assignment = $this->mkAssignment(
            id: 100,
            userId: $organizerId,  // Gleicher User = Self-Approval
            status: EventTaskAssignment::STATUS_VORGESCHLAGEN,
        );
        $task = $this->mkTask(10);
        $event = $this->mkEvent(1);

        $service = $this->mkService(
            assignmentById: [100 => $assignment],
            taskById: [10 => $task],
            eventById: [1 => $event],
            isOrganizer: fn (int $eid, int $uid) => $eid === 1 && $uid === $organizerId,
        );

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/Eigene Zusagen koennen nicht selbst freigegeben/');

        $service->approveTime(100, $organizerId);
    }

    public function test_approveTime_requires_organizer_role(): void
    {
        $nonOrganizerId = 999;
        $assignment = $this->mkAssignment(
            id: 100,
            userId: 7,
            status: EventTaskAssignment::STATUS_VORGESCHLAGEN,
        );

        $service = $this->mkService(
            assignmentById: [100 => $assignment],
            taskById: [10 => $this->mkTask(10)],
            eventById: [1 => $this->mkEvent(1)],
            isOrganizer: fn () => false,  // Nicht Organisator
        );

        $this->expectException(AuthorizationException::class);
        $service->approveTime(100, $nonOrganizerId);
    }

    public function test_approveTime_succeeds_for_different_organizer(): void
    {
        $organizerId = 42;
        $assigneeUserId = 7;  // Nicht der Organisator
        $assignment = $this->mkAssignment(
            id: 100,
            userId: $assigneeUserId,
            status: EventTaskAssignment::STATUS_VORGESCHLAGEN,
        );

        $statusChanged = null;
        $service = $this->mkService(
            assignmentById: [100 => $assignment],
            taskById: [10 => $this->mkTask(10)],
            eventById: [1 => $this->mkEvent(1)],
            isOrganizer: fn () => true,
            onChangeStatus: function (int $id, string $newStatus) use (&$statusChanged) {
                $statusChanged = [$id, $newStatus];
            },
        );

        $service->approveTime(100, $organizerId);

        self::assertSame([100, EventTaskAssignment::STATUS_BESTAETIGT], $statusChanged);
    }

    public function test_rejectTime_requires_reason(): void
    {
        $service = $this->mkService();
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/Begruendung/');
        $service->rejectTime(100, 42, '');
    }

    public function test_assignMember_rejects_duplicate(): void
    {
        $task = $this->mkTask(10);
        $event = $this->mkEvent(1, status: Event::STATUS_VEROEFFENTLICHT);

        $service = $this->mkService(
            taskById: [10 => $task],
            eventById: [1 => $event],
            hasActiveAssignment: fn () => true,  // Bereits Assignment vorhanden
        );

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/bereits uebernommen/');
        $service->assignMember(10, 7);
    }

    public function test_assignMember_rejects_unpublished_event(): void
    {
        $task = $this->mkTask(10);
        $event = $this->mkEvent(1, status: Event::STATUS_ENTWURF);

        $service = $this->mkService(
            taskById: [10 => $task],
            eventById: [1 => $event],
        );

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/nicht veroeffentlicht|abgeschlossen/');
        $service->assignMember(10, 7);
    }

    public function test_assignMember_respects_maximum_capacity(): void
    {
        $task = $this->mkTask(
            10,
            capacityMode: EventTask::CAP_MAXIMUM,
            capacityTarget: 3,
        );
        $event = $this->mkEvent(1, status: Event::STATUS_VEROEFFENTLICHT);

        $service = $this->mkService(
            taskById: [10 => $task],
            eventById: [1 => $event],
            countActiveByTask: fn () => 3,  // schon voll
        );

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/maximale Anzahl/');
        $service->assignMember(10, 7);
    }

    public function test_assignMember_fix_slot_ignores_user_proposed_times(): void
    {
        $task = $this->mkTask(10, slotMode: EventTask::SLOT_FIX);
        $event = $this->mkEvent(1, status: Event::STATUS_VEROEFFENTLICHT);

        $createdPayload = null;
        $service = $this->mkService(
            taskById: [10 => $task],
            eventById: [1 => $event],
            onCreate: function (array $data) use (&$createdPayload) {
                $createdPayload = $data;
                return 42;
            },
            findByIdAfterCreate: fn () => $this->mkAssignment(42, 7, EventTaskAssignment::STATUS_BESTAETIGT),
        );

        $result = $service->assignMember(10, 7, '2026-06-08 14:00:00', '2026-06-08 16:00:00');

        self::assertNotNull($createdPayload);
        self::assertNull($createdPayload['proposed_start'], 'Bei slot_mode=fix darf kein Vorschlag gespeichert werden');
        self::assertNull($createdPayload['proposed_end']);
        self::assertSame(EventTaskAssignment::STATUS_BESTAETIGT, $createdPayload['status']);
    }

    public function test_assignMember_variable_slot_requires_proposed_times(): void
    {
        $task = $this->mkTask(10, slotMode: EventTask::SLOT_VARIABEL);
        $event = $this->mkEvent(1, status: Event::STATUS_VEROEFFENTLICHT);

        $service = $this->mkService(
            taskById: [10 => $task],
            eventById: [1 => $event],
        );

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/Start und Ende vorgeschlagen/');
        $service->assignMember(10, 7);  // kein proposedStart/End
    }

    public function test_withdrawSelf_only_allowed_for_own_unconfirmed(): void
    {
        $ownerId = 7;
        $a = $this->mkAssignment(
            id: 100,
            userId: $ownerId,
            status: EventTaskAssignment::STATUS_BESTAETIGT,  // schon bestaetigt
        );
        $service = $this->mkService(assignmentById: [100 => $a]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/bestaetigte Zusagen koennen nur ueber Storno/i');
        $service->withdrawSelf(100, $ownerId);
    }

    public function test_withdrawSelf_rejects_foreign_assignment(): void
    {
        $a = $this->mkAssignment(
            id: 100,
            userId: 7,
            status: EventTaskAssignment::STATUS_VORGESCHLAGEN,
        );
        $service = $this->mkService(assignmentById: [100 => $a]);

        $this->expectException(AuthorizationException::class);
        $service->withdrawSelf(100, 999);  // Fremder User
    }

    // =========================================================================
    // Fixture-Builder
    // =========================================================================

    private function mkEvent(
        int $id,
        string $status = Event::STATUS_VEROEFFENTLICHT,
        int $deadlineHours = 24
    ): Event {
        return Event::fromArray([
            'id' => $id,
            'title' => "Event-$id",
            'start_at' => '2030-01-01 10:00:00',  // weit in der Zukunft
            'end_at' => '2030-01-01 22:00:00',
            'status' => $status,
            'cancel_deadline_hours' => $deadlineHours,
        ]);
    }

    private function mkTask(
        int $id,
        string $slotMode = EventTask::SLOT_FIX,
        string $capacityMode = EventTask::CAP_UNBEGRENZT,
        ?int $capacityTarget = null
    ): EventTask {
        return EventTask::fromArray([
            'id' => $id,
            'event_id' => 1,
            'title' => "Task-$id",
            'slot_mode' => $slotMode,
            'capacity_mode' => $capacityMode,
            'capacity_target' => $capacityTarget,
            'start_at' => '2030-01-01 14:00:00',
            'end_at' => '2030-01-01 17:00:00',
            'hours_default' => 3.0,
        ]);
    }

    private function mkAssignment(
        int $id,
        int $userId,
        string $status
    ): EventTaskAssignment {
        return EventTaskAssignment::fromArray([
            'id' => $id,
            'task_id' => 10,
            'user_id' => $userId,
            'status' => $status,
        ]);
    }

    /**
     * Baut den Service mit anonymen Repo-Mocks. Alle Callbacks optional.
     */
    private function mkService(
        array $assignmentById = [],
        array $taskById = [],
        array $eventById = [],
        \Closure $isOrganizer = null,
        \Closure $hasActiveAssignment = null,
        \Closure $countActiveByTask = null,
        \Closure $onCreate = null,
        \Closure $findByIdAfterCreate = null,
        \Closure $onChangeStatus = null
    ): EventAssignmentService {
        $eventRepo = new class($eventById) extends EventRepository {
            public function __construct(private array $events) { /* no parent */ }
            public function findById(int $id): ?Event { return $this->events[$id] ?? null; }
        };

        $taskRepo = new class($taskById) extends EventTaskRepository {
            public function __construct(private array $tasks) {}
            public function findById(int $id): ?EventTask { return $this->tasks[$id] ?? null; }
        };

        $assignmentRepo = new class($assignmentById, $hasActiveAssignment, $countActiveByTask, $onCreate, $findByIdAfterCreate, $onChangeStatus) extends EventTaskAssignmentRepository {
            public function __construct(
                private array $items,
                private ?\Closure $hasActive,
                private ?\Closure $countActive,
                private ?\Closure $onCreate,
                private ?\Closure $findAfterCreate,
                private ?\Closure $onChange,
            ) {}
            public function findById(int $id): ?EventTaskAssignment {
                if ($this->findAfterCreate !== null) {
                    return ($this->findAfterCreate)($id);
                }
                return $this->items[$id] ?? null;
            }
            public function hasActiveAssignment(int $taskId, int $userId): bool {
                return $this->hasActive !== null ? ($this->hasActive)($taskId, $userId) : false;
            }
            public function countActiveByTask(int $taskId): int {
                return $this->countActive !== null ? ($this->countActive)($taskId) : 0;
            }
            public function create(array $data): int {
                return $this->onCreate !== null ? ($this->onCreate)($data) : 1;
            }
            public function changeStatus(int $id, string $newStatus): bool {
                if ($this->onChange !== null) ($this->onChange)($id, $newStatus);
                return true;
            }
            public function setReplacement(int $id, ?int $userId): bool { return true; }
            public function softDelete(int $id, int $by): bool { return true; }
        };

        $organizerRepo = new class($isOrganizer) extends EventOrganizerRepository {
            public function __construct(private ?\Closure $cb) {}
            public function isOrganizer(int $eventId, int $userId): bool {
                return $this->cb !== null ? ($this->cb)($eventId, $userId) : true;
            }
        };

        $audit = new class extends AuditService {
            public function __construct() {}
            public function log(
                string $action,
                ?string $tableName = null,
                ?int $recordId = null,
                ?array $oldValues = null,
                ?array $newValues = null,
                ?string $description = null,
                ?string $entryNumber = null,
                ?array $metadata = null
            ): void {
                // no-op
            }
        };

        return new EventAssignmentService(
            $eventRepo,
            $taskRepo,
            $assignmentRepo,
            $organizerRepo,
            $audit
        );
    }
}
