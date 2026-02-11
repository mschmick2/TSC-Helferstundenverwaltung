<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Repository für Dialog-Nachrichten (work_entry_dialogs)
 *
 * Nachrichten sind unveränderlich (Revisionssicherheit) - kein Update/Delete.
 */
class DialogRepository
{
    public function __construct(
        private \PDO $pdo
    ) {
    }

    /**
     * Alle Nachrichten zu einem Eintrag laden
     *
     * @return array[]
     */
    public function findByEntryId(int $entryId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT wed.*,
                    CONCAT(u.vorname, ' ', u.nachname) AS user_name
             FROM work_entry_dialogs wed
             JOIN users u ON wed.user_id = u.id
             WHERE wed.work_entry_id = :entry_id
             ORDER BY wed.created_at ASC"
        );
        $stmt->execute(['entry_id' => $entryId]);
        return $stmt->fetchAll();
    }

    /**
     * Neue Nachricht erstellen
     */
    public function create(int $entryId, int $userId, string $message, bool $isQuestion = false): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO work_entry_dialogs (work_entry_id, user_id, message, is_question)
             VALUES (:entry_id, :user_id, :message, :is_question)"
        );
        $stmt->execute([
            'entry_id' => $entryId,
            'user_id' => $userId,
            'message' => $message,
            'is_question' => $isQuestion ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Offene Fragen eines Eintrags als beantwortet markieren
     */
    public function markQuestionsAnswered(int $entryId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE work_entry_dialogs
             SET is_answered = TRUE
             WHERE work_entry_id = :entry_id AND is_question = TRUE AND is_answered = FALSE"
        );
        $stmt->execute(['entry_id' => $entryId]);
    }

    /**
     * Anzahl offener Fragen zu einem Eintrag
     */
    public function countOpenQuestions(int $entryId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM work_entry_dialogs
             WHERE work_entry_id = :entry_id AND is_question = TRUE AND is_answered = FALSE"
        );
        $stmt->execute(['entry_id' => $entryId]);
        return (int) $stmt->fetchColumn();
    }
}
