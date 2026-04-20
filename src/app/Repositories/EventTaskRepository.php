<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\EventTask;
use PDO;

/**
 * Repository fuer Event-Aufgaben (und Beigaben).
 */
class EventTaskRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return EventTask[]
     */
    public function findByEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM event_tasks
             WHERE event_id = :event_id AND deleted_at IS NULL
             ORDER BY sort_order ASC, title ASC"
        );
        $stmt->execute(['event_id' => $eventId]);

        $tasks = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tasks[] = EventTask::fromArray($row);
        }
        return $tasks;
    }

    public function findById(int $id): ?EventTask
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM event_tasks WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? EventTask::fromArray($row) : null;
    }

    public function getRawById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM event_tasks WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Neue Aufgabe anlegen.
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO event_tasks
             (event_id, category_id, title, description, task_type, slot_mode,
              start_at, end_at, capacity_mode, capacity_target, hours_default, sort_order)
             VALUES
             (:event_id, :category_id, :title, :description, :task_type, :slot_mode,
              :start_at, :end_at, :capacity_mode, :capacity_target, :hours_default, :sort_order)"
        );
        $stmt->execute([
            'event_id' => $data['event_id'],
            'category_id' => $data['category_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'task_type' => $data['task_type'] ?? EventTask::TYPE_AUFGABE,
            'slot_mode' => $data['slot_mode'] ?? EventTask::SLOT_FIX,
            'start_at' => $data['start_at'] ?? null,
            'end_at' => $data['end_at'] ?? null,
            'capacity_mode' => $data['capacity_mode'] ?? EventTask::CAP_UNBEGRENZT,
            'capacity_target' => $data['capacity_target'] ?? null,
            'hours_default' => $data['hours_default'] ?? 0.0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Task aktualisieren. version-Spalte wird immer inkrementiert, damit Aussen-
     * stehende (Event-Admin-UI) eine verlaessliche Versionsnummer erhalten.
     * Eine Version-Pruefung (Optimistic Lock) gibt es derzeit nicht, weil Tasks
     * nur ueber die Event-Detail-Seite editiert werden und dort der Event-Schutz
     * schon greift. Parameter $expectedVersion ist fuer spaetere Aktivierung reserviert.
     */
    public function update(int $id, array $data, ?int $expectedVersion = null): bool
    {
        $sql = "UPDATE event_tasks SET
                    category_id = :category_id,
                    title = :title,
                    description = :description,
                    task_type = :task_type,
                    slot_mode = :slot_mode,
                    start_at = :start_at,
                    end_at = :end_at,
                    capacity_mode = :capacity_mode,
                    capacity_target = :capacity_target,
                    hours_default = :hours_default,
                    sort_order = :sort_order,
                    version = version + 1
                WHERE id = :id AND deleted_at IS NULL";
        $params = [
            'category_id' => $data['category_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'task_type' => $data['task_type'] ?? EventTask::TYPE_AUFGABE,
            'slot_mode' => $data['slot_mode'] ?? EventTask::SLOT_FIX,
            'start_at' => $data['start_at'] ?? null,
            'end_at' => $data['end_at'] ?? null,
            'capacity_mode' => $data['capacity_mode'] ?? EventTask::CAP_UNBEGRENZT,
            'capacity_target' => $data['capacity_target'] ?? null,
            'hours_default' => $data['hours_default'] ?? 0.0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'id' => $id,
        ];
        if ($expectedVersion !== null) {
            $sql .= " AND version = :version";
            $params['version'] = $expectedVersion;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    public function softDelete(int $id, int $deletedBy): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_tasks SET deleted_at = NOW(), deleted_by = :user, version = version + 1
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['user' => $deletedBy, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Aktive Zusagen (vorgeschlagen + bestaetigt + storno_angefragt) fuer
     * Capacity-Check auf Task-Ebene. Storniert/abgeschlossen zaehlen nicht -
     * der Slot ist wieder frei.
     */
    public function countActiveAssignments(int $taskId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM event_task_assignments
             WHERE task_id = :task_id
               AND status IN (:s_vorg, :s_best, :s_storno)
               AND deleted_at IS NULL"
        );
        $stmt->execute([
            'task_id'  => $taskId,
            's_vorg'   => \App\Models\EventTaskAssignment::STATUS_VORGESCHLAGEN,
            's_best'   => \App\Models\EventTaskAssignment::STATUS_BESTAETIGT,
            's_storno' => \App\Models\EventTaskAssignment::STATUS_STORNO_ANGEFRAGT,
        ]);
        return (int) $stmt->fetchColumn();
    }
}
