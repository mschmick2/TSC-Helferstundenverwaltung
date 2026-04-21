<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Repository fuer Event-Organizer-Zuordnungen.
 */
class EventOrganizerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function assign(int $eventId, int $userId, int $assignedBy): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO event_organizers (event_id, user_id, assigned_by)
             VALUES (:event_id, :user_id, :assigned_by)"
        );
        $stmt->execute([
            'event_id' => $eventId,
            'user_id' => $userId,
            'assigned_by' => $assignedBy,
        ]);
    }

    public function revoke(int $eventId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM event_organizers
             WHERE event_id = :event_id AND user_id = :user_id"
        );
        $stmt->execute(['event_id' => $eventId, 'user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Ist User Organisator des Events?
     */
    public function isOrganizer(int $eventId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM event_organizers
             WHERE event_id = :event_id AND user_id = :user_id
             LIMIT 1"
        );
        $stmt->execute(['event_id' => $eventId, 'user_id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Liste der Organizer-User-Datensaetze fuer Event (join mit users).
     *
     * @return array<int, array{user_id:int, vorname:string, nachname:string, email:string}>
     */
    public function listForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id AS user_id, u.vorname, u.nachname, u.email
             FROM event_organizers eo
             JOIN users u ON u.id = eo.user_id AND u.deleted_at IS NULL
             WHERE eo.event_id = :event_id
             ORDER BY u.nachname, u.vorname"
        );
        $stmt->execute(['event_id' => $eventId]);

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                'user_id' => (int) $row['user_id'],
                'vorname' => (string) $row['vorname'],
                'nachname' => (string) $row['nachname'],
                'email' => (string) $row['email'],
            ];
        }
        return $rows;
    }

    /**
     * @return int[] user-IDs
     */
    public function listUserIdsForEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT user_id FROM event_organizers WHERE event_id = :event_id"
        );
        $stmt->execute(['event_id' => $eventId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return int[] Event-IDs, bei denen User Organisator ist
     */
    public function findEventIdsForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT event_id FROM event_organizers WHERE user_id = :user_id"
        );
        $stmt->execute(['user_id' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
