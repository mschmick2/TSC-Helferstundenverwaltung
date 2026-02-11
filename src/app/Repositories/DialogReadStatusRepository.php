<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Repository fuer Dialog-Lese-Status (dialog_read_status)
 *
 * Trackt pro Benutzer und Antrag, wann die Detailseite zuletzt aufgerufen wurde.
 * Wird verwendet, um auf dem Dashboard ungelesene Dialog-Nachrichten anzuzeigen.
 */
class DialogReadStatusRepository
{
    public function __construct(
        private \PDO $pdo
    ) {
    }

    /**
     * Antrag als gelesen markieren (Upsert)
     */
    public function markAsRead(int $userId, int $workEntryId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO dialog_read_status (user_id, work_entry_id, last_read_at)
             VALUES (:user_id, :work_entry_id, NOW())
             ON DUPLICATE KEY UPDATE last_read_at = NOW()"
        );
        $stmt->execute([
            'user_id' => $userId,
            'work_entry_id' => $workEntryId,
        ]);
    }

    /**
     * Antraege mit ungelesenen Dialog-Nachrichten fuer einen Benutzer finden
     *
     * Gibt Antraege zurueck, bei denen andere Benutzer neue Nachrichten geschrieben haben,
     * seit der aktuelle Benutzer die Detailseite zuletzt aufgerufen hat.
     *
     * @param bool $canReview true wenn der Benutzer Pruefer/Admin ist (sieht alle offenen Antraege)
     * @return array[] Liste mit entry_id, entry_number, status, entry_owner_name, unread_count, latest_message_at
     */
    public function findUnreadDialogsForUser(int $userId, bool $canReview = false): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                we.id AS entry_id,
                we.entry_number,
                we.status,
                CONCAT(owner.vorname, ' ', owner.nachname) AS entry_owner_name,
                COUNT(wed.id) AS unread_count,
                MAX(wed.created_at) AS latest_message_at
             FROM work_entries we
             JOIN users owner ON we.user_id = owner.id
             JOIN work_entry_dialogs wed ON wed.work_entry_id = we.id
             LEFT JOIN dialog_read_status drs
                ON drs.user_id = :userId AND drs.work_entry_id = we.id
             WHERE we.deleted_at IS NULL
               AND we.status IN ('eingereicht', 'in_klaerung')
               AND wed.user_id != :userId2
               AND wed.created_at > COALESCE(drs.last_read_at, '1970-01-01 00:00:00')
               AND (
                   we.user_id = :userId3
                   OR we.created_by_user_id = :userId4
                   OR we.reviewed_by_user_id = :userId5
                   OR :canReview = 1
               )
             GROUP BY we.id, we.entry_number, we.status, owner.vorname, owner.nachname
             ORDER BY MAX(wed.created_at) DESC"
        );
        $stmt->execute([
            'userId' => $userId,
            'userId2' => $userId,
            'userId3' => $userId,
            'userId4' => $userId,
            'userId5' => $userId,
            'canReview' => $canReview ? 1 : 0,
        ]);
        return $stmt->fetchAll();
    }
}
