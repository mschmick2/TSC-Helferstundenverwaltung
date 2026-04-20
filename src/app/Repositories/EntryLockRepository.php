<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Repository fuer Bearbeitungssperren auf work_entries (Modul 7 I1).
 *
 * Die Tabelle entry_locks hat UNIQUE(work_entry_id) — dadurch wird das
 * Acquiring atomar in einem einzigen INSERT ... ON DUPLICATE KEY UPDATE
 * abgewickelt. Session-FK mit ON DELETE CASCADE raeumt automatisch auf,
 * wenn der Nutzer sich ausloggt oder die Session ablaeuft.
 */
class EntryLockRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Versucht, einen Lock auf (entryId) zu setzen oder zu verlaengern.
     *
     * Verhalten:
     *   - Kein Eintrag vorhanden          → INSERT, Lock dem User zugeordnet.
     *   - Eigener Lock vorhanden          → expires_at wird verlaengert.
     *   - Fremder Lock, aber abgelaufen   → wird an den neuen User uebertragen.
     *   - Fremder Lock, noch aktiv        → null.
     *
     * Rueckgabe: array mit der Lock-Zeile bei Erfolg, null bei Konflikt.
     *
     * @return array<string, mixed>|null
     */
    public function acquireOrRefresh(int $entryId, int $userId, ?int $sessionId, int $ttlMinutes): ?array
    {
        $sql = "INSERT INTO entry_locks (work_entry_id, user_id, session_id, locked_at, expires_at)
                VALUES (:entry_id, :user_id, :session_id, NOW(), DATE_ADD(NOW(), INTERVAL :ttl MINUTE))
                ON DUPLICATE KEY UPDATE
                    user_id = IF(user_id = VALUES(user_id) OR expires_at <= NOW(), VALUES(user_id), user_id),
                    session_id = IF(user_id = VALUES(user_id), VALUES(session_id), session_id),
                    locked_at = IF(user_id = VALUES(user_id) OR expires_at <= NOW(), NOW(), locked_at),
                    expires_at = IF(user_id = VALUES(user_id) OR expires_at <= NOW(), VALUES(expires_at), expires_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'entry_id'   => $entryId,
            'user_id'    => $userId,
            'session_id' => $sessionId,
            'ttl'        => $ttlMinutes,
        ]);

        $current = $this->findActive($entryId);

        if ($current === null) {
            return null;
        }

        return ((int) $current['user_id'] === $userId) ? $current : null;
    }

    /**
     * Loescht den eigenen Lock. Gibt die Anzahl betroffener Zeilen zurueck
     * (0, wenn kein eigener Lock mehr existierte, 1 bei Erfolg).
     */
    public function releaseByUser(int $entryId, int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM entry_locks WHERE work_entry_id = :entry_id AND user_id = :user_id'
        );
        $stmt->execute(['entry_id' => $entryId, 'user_id' => $userId]);

        return $stmt->rowCount();
    }

    /**
     * Aktive (nicht abgelaufene) Lock-Zeile fuer entryId oder null.
     *
     * @return array<string, mixed>|null
     */
    public function findActive(int $entryId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, work_entry_id, user_id, session_id, locked_at, expires_at
             FROM entry_locks
             WHERE work_entry_id = :entry_id AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute(['entry_id' => $entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Haushalt: Loescht alle abgelaufenen Locks. Gibt die Anzahl geloeschter
     * Zeilen zurueck.
     */
    public function deleteStale(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM entry_locks WHERE expires_at <= NOW()');
        $stmt->execute();

        return $stmt->rowCount();
    }
}
