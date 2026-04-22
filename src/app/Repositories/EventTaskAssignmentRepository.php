<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\EventTaskAssignment;
use PDO;

/**
 * Repository fuer Task-Zusagen.
 *
 * I1 liefert nur CRUD-Basics; die Workflow-Logik (Capacity-Check,
 * Deadline-Pruefung, Ersatz-Vorschlag) lebt ab I2 in EventAssignmentService.
 */
class EventTaskAssignmentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return EventTaskAssignment[]
     */
    public function findByTask(int $taskId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM event_task_assignments
             WHERE task_id = :task_id AND deleted_at IS NULL
             ORDER BY created_at ASC"
        );
        $stmt->execute(['task_id' => $taskId]);

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = EventTaskAssignment::fromArray($row);
        }
        return $rows;
    }

    /**
     * @return EventTaskAssignment[]
     */
    public function findByUser(int $userId, bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM event_task_assignments
                WHERE user_id = :user_id AND deleted_at IS NULL";
        if ($activeOnly) {
            $sql .= " AND status IN ('vorgeschlagen','bestaetigt','storno_angefragt')";
        }
        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId]);

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = EventTaskAssignment::fromArray($row);
        }
        return $rows;
    }

    public function findById(int $id): ?EventTaskAssignment
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM event_task_assignments WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? EventTaskAssignment::fromArray($row) : null;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO event_task_assignments
             (task_id, user_id, status, proposed_start, proposed_end, actual_hours)
             VALUES
             (:task_id, :user_id, :status, :proposed_start, :proposed_end, :actual_hours)"
        );
        $stmt->execute([
            'task_id' => $data['task_id'],
            'user_id' => $data['user_id'],
            'status' => $data['status'] ?? EventTaskAssignment::STATUS_VORGESCHLAGEN,
            'proposed_start' => $data['proposed_start'] ?? null,
            'proposed_end' => $data['proposed_end'] ?? null,
            'actual_hours' => $data['actual_hours'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function changeStatus(int $id, string $newStatus): bool
    {
        $allowed = [
            EventTaskAssignment::STATUS_VORGESCHLAGEN,
            EventTaskAssignment::STATUS_BESTAETIGT,
            EventTaskAssignment::STATUS_STORNO_ANGEFRAGT,
            EventTaskAssignment::STATUS_STORNIERT,
            EventTaskAssignment::STATUS_ABGESCHLOSSEN,
        ];
        if (!in_array($newStatus, $allowed, true)) {
            throw new \InvalidArgumentException("Ungueltiger Assignment-Status: $newStatus");
        }

        $stmt = $this->pdo->prepare(
            "UPDATE event_task_assignments SET status = :status, version = version + 1
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['status' => $newStatus, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function setWorkEntryId(int $assignmentId, int $workEntryId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_task_assignments SET work_entry_id = :we, version = version + 1
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['we' => $workEntryId, 'id' => $assignmentId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Ersatz-Vorschlag setzen (beim Storno-Request).
     */
    public function setReplacement(int $assignmentId, ?int $replacementUserId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_task_assignments SET replacement_suggested_user_id = :r, version = version + 1
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['r' => $replacementUserId, 'id' => $assignmentId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Hat User bereits eine aktive Zusage fuer die Task?
     * (vorgeschlagen / bestaetigt / storno_angefragt zaehlen als aktiv)
     */
    public function hasActiveAssignment(int $taskId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM event_task_assignments
             WHERE task_id = :task_id AND user_id = :user_id
               AND status IN (:s_vorg, :s_best, :s_storno)
               AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([
            'task_id' => $taskId,
            'user_id' => $userId,
            's_vorg'   => EventTaskAssignment::STATUS_VORGESCHLAGEN,
            's_best'   => EventTaskAssignment::STATUS_BESTAETIGT,
            's_storno' => EventTaskAssignment::STATUS_STORNO_ANGEFRAGT,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Anzahl aktiver Zusagen fuer eine Task (Capacity-Check).
     */
    public function countActiveByTask(int $taskId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM event_task_assignments
             WHERE task_id = :task_id
               AND status IN (:s_vorg, :s_best, :s_storno)
               AND deleted_at IS NULL"
        );
        $stmt->execute([
            'task_id' => $taskId,
            's_vorg'   => EventTaskAssignment::STATUS_VORGESCHLAGEN,
            's_best'   => EventTaskAssignment::STATUS_BESTAETIGT,
            's_storno' => EventTaskAssignment::STATUS_STORNO_ANGEFRAGT,
        ]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Anzahl aktiver Zusagen pro Task fuer ein Event als Map task_id -> count.
     * Eine einzige Query statt N — vom TaskTreeAggregator zur Berechnung von
     * open_slots_subtree genutzt (Modul 6 I7b).
     *
     * Tasks ohne aktive Zusagen erscheinen NICHT in der Map. Caller verwenden
     * `$counts[$taskId] ?? 0`.
     *
     * @return array<int,int>
     */
    public function countActiveByEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT eta.task_id, COUNT(*) AS cnt
             FROM event_task_assignments eta
             JOIN event_tasks et ON et.id = eta.task_id
             WHERE et.event_id = :event_id
               AND eta.status IN (:s_vorg, :s_best, :s_storno)
               AND eta.deleted_at IS NULL
             GROUP BY eta.task_id"
        );
        $stmt->execute([
            'event_id' => $eventId,
            's_vorg'   => EventTaskAssignment::STATUS_VORGESCHLAGEN,
            's_best'   => EventTaskAssignment::STATUS_BESTAETIGT,
            's_storno' => EventTaskAssignment::STATUS_STORNO_ANGEFRAGT,
        ]);

        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(int) $row['task_id']] = (int) $row['cnt'];
        }
        return $map;
    }

    /**
     * Alle bestaetigten Zusagen eines Events (fuer Event-Abschluss in I3).
     *
     * @return EventTaskAssignment[]
     */
    public function findConfirmedForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT eta.*
             FROM event_task_assignments eta
             JOIN event_tasks et ON et.id = eta.task_id AND et.deleted_at IS NULL
             WHERE et.event_id = :event_id
               AND eta.status = :status
               AND eta.deleted_at IS NULL
             ORDER BY eta.created_at ASC"
        );
        $stmt->execute([
            'event_id' => $eventId,
            'status' => EventTaskAssignment::STATUS_BESTAETIGT,
        ]);

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = EventTaskAssignment::fromArray($row);
        }
        return $rows;
    }

    /**
     * Offene Review-Items fuer einen Organisator:
     * - vorgeschlagene variable Zeitfenster (muessen bestaetigt/abgelehnt werden)
     * - angefragte Stornos (muessen freigegeben/abgelehnt werden)
     * gefiltert auf Events, bei denen der User Organisator ist.
     *
     * @return EventTaskAssignment[]
     */
    public function findPendingReviewsForOrganizer(int $organizerId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT eta.*
             FROM event_task_assignments eta
             JOIN event_tasks et ON et.id = eta.task_id AND et.deleted_at IS NULL
             JOIN event_organizers eo ON eo.event_id = et.event_id
             WHERE eo.user_id = :org_id
               AND eta.status IN (:s_vorg, :s_storno)
               AND eta.deleted_at IS NULL
             ORDER BY eta.created_at ASC"
        );
        $stmt->execute([
            'org_id' => $organizerId,
            's_vorg'   => EventTaskAssignment::STATUS_VORGESCHLAGEN,
            's_storno' => EventTaskAssignment::STATUS_STORNO_ANGEFRAGT,
        ]);

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = EventTaskAssignment::fromArray($row);
        }
        return $rows;
    }

    public function softDelete(int $id, int $deletedBy): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_task_assignments SET deleted_at = NOW(), deleted_by = :user, version = version + 1
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['user' => $deletedBy, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
