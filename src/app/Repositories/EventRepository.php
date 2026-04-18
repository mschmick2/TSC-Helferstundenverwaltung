<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Event;
use PDO;

/**
 * Repository fuer Events.
 */
class EventRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Alle aktiven (nicht soft-deleted) Events fuer Event-Admin-Liste.
     *
     * @return Event[]
     */
    public function findAllForAdmin(?string $statusFilter = null): array
    {
        $sql = "SELECT * FROM events WHERE deleted_at IS NULL";
        $params = [];

        if ($statusFilter !== null && in_array($statusFilter, Event::ALL_STATUSES, true)) {
            $sql .= " AND status = :status";
            $params['status'] = $statusFilter;
        }

        $sql .= " ORDER BY start_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $events = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $events[] = Event::fromArray($row);
        }
        return $events;
    }

    /**
     * Oeffentlich sichtbare Events (veroeffentlicht, nicht abgeschlossen/abgesagt).
     *
     * @return Event[]
     */
    public function findPublished(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM events
             WHERE status = :status AND deleted_at IS NULL AND end_at >= NOW()
             ORDER BY start_at ASC"
        );
        $stmt->execute(['status' => Event::STATUS_VEROEFFENTLICHT]);

        $events = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $events[] = Event::fromArray($row);
        }
        return $events;
    }

    /**
     * Events, bei denen der User als Organisator eingetragen ist.
     *
     * @return Event[]
     */
    public function findForOrganizer(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.* FROM events e
             JOIN event_organizers eo ON eo.event_id = e.id
             WHERE eo.user_id = :user_id AND e.deleted_at IS NULL
             ORDER BY e.start_at DESC"
        );
        $stmt->execute(['user_id' => $userId]);

        $events = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $events[] = Event::fromArray($row);
        }
        return $events;
    }

    public function findById(int $id): ?Event
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM events WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? Event::fromArray($row) : null;
    }

    /**
     * Rohdaten fuer Audit-Trail (old_values).
     */
    public function getRawById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM events WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Neues Event anlegen.
     *
     * @param array{title:string, description?:?string, location?:?string,
     *              start_at:string, end_at:string,
     *              cancel_deadline_hours?:?int, created_by:int} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO events
             (title, description, location, start_at, end_at,
              status, cancel_deadline_hours, created_by,
              source_template_id, source_template_version)
             VALUES
             (:title, :description, :location, :start_at, :end_at,
              :status, :cancel_deadline_hours, :created_by,
              :source_template_id, :source_template_version)"
        );
        $stmt->execute([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'start_at' => $data['start_at'],
            'end_at' => $data['end_at'],
            'status' => Event::STATUS_ENTWURF,
            'cancel_deadline_hours' => $data['cancel_deadline_hours'] ?? Event::DEFAULT_CANCEL_DEADLINE_HOURS,
            'created_by' => $data['created_by'],
            'source_template_id' => $data['source_template_id'] ?? null,
            'source_template_version' => $data['source_template_version'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE events SET
                title = :title,
                description = :description,
                location = :location,
                start_at = :start_at,
                end_at = :end_at,
                cancel_deadline_hours = :cancel_deadline_hours
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'location' => $data['location'] ?? null,
            'start_at' => $data['start_at'],
            'end_at' => $data['end_at'],
            'cancel_deadline_hours' => $data['cancel_deadline_hours'] ?? Event::DEFAULT_CANCEL_DEADLINE_HOURS,
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function changeStatus(int $id, string $newStatus): bool
    {
        if (!in_array($newStatus, Event::ALL_STATUSES, true)) {
            throw new \InvalidArgumentException("Ungueltiger Event-Status: $newStatus");
        }

        $stmt = $this->pdo->prepare(
            "UPDATE events SET status = :status
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['status' => $newStatus, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function softDelete(int $id, int $deletedBy): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE events SET deleted_at = NOW(), deleted_by = :user
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['user' => $deletedBy, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }
}
