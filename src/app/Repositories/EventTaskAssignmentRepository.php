<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\EventTaskAssignment;
use PDO;

/**
 * Repository fuer Task-Zusagen.
 *
 * I1 liefert nur CRUD-Basics; die Workflow-Logik (Capacity-Check,
 * Deadline-Pruefung, Ersatz-Vorschlag) kommt in I2.
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
            "UPDATE event_task_assignments SET status = :status
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['status' => $newStatus, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function setWorkEntryId(int $assignmentId, int $workEntryId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_task_assignments SET work_entry_id = :we
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['we' => $workEntryId, 'id' => $assignmentId]);
        return $stmt->rowCount() > 0;
    }

    public function softDelete(int $id, int $deletedBy): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_task_assignments SET deleted_at = NOW(), deleted_by = :user
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['user' => $deletedBy, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
