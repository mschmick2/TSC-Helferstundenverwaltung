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
     * Pro-Session-Semantik (T-E23, Option A): "Eigener Lock" = gleiche Session
     * UND gleicher User. Zwei getrennte Sessions desselben Users kollidieren,
     * damit Cross-Browser-Edit erkannt wird.
     *
     * Verhalten:
     *   - Kein Eintrag vorhanden                      → INSERT.
     *   - Eigener Lock (gleiche Session, gleicher User) → expires_at verlaengert.
     *   - Fremder Lock, aber abgelaufen               → wird uebertragen.
     *   - Fremder Lock, noch aktiv                    → null.
     *
     * Rueckgabe: array mit der Lock-Zeile bei Erfolg, null bei Konflikt.
     *
     * @return array<string, mixed>|null
     */
    public function acquireOrRefresh(int $entryId, int $userId, ?int $sessionId, int $ttlMinutes): ?array
    {
        // <=> ist MySQLs NULL-safe equal: NULL<=>NULL == 1, NULL<=>5 == 0.
        // Gleichheit beider Felder markiert den eigenen aktiven Lock; nur
        // dann (oder bei abgelaufenem Lock) wird refreshed/uebernommen.
        $sql = "INSERT INTO entry_locks (work_entry_id, user_id, session_id, locked_at, expires_at)
                VALUES (:entry_id, :user_id, :session_id, NOW(), DATE_ADD(NOW(), INTERVAL :ttl MINUTE))
                ON DUPLICATE KEY UPDATE
                    user_id    = IF((session_id <=> VALUES(session_id) AND user_id = VALUES(user_id)) OR expires_at <= NOW(), VALUES(user_id), user_id),
                    session_id = IF((session_id <=> VALUES(session_id) AND user_id = VALUES(user_id)) OR expires_at <= NOW(), VALUES(session_id), session_id),
                    locked_at  = IF((session_id <=> VALUES(session_id) AND user_id = VALUES(user_id)) OR expires_at <= NOW(), NOW(), locked_at),
                    expires_at = IF((session_id <=> VALUES(session_id) AND user_id = VALUES(user_id)) OR expires_at <= NOW(), VALUES(expires_at), expires_at)";

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

        $currentSession = $current['session_id'] === null ? null : (int) $current['session_id'];
        $sessionMatches = $currentSession === $sessionId;
        $userMatches = (int) $current['user_id'] === $userId;

        return ($sessionMatches && $userMatches) ? $current : null;
    }

    /**
     * Loescht den eigenen Lock (gleiche Session UND User). Gibt die Anzahl
     * betroffener Zeilen zurueck (0, wenn kein eigener Lock mehr existierte).
     *
     * NULL-Session (Tests/Legacy) matcht ueber IS NULL.
     */
    public function releaseBySession(int $entryId, int $userId, ?int $sessionId): int
    {
        if ($sessionId === null) {
            $stmt = $this->pdo->prepare(
                'DELETE FROM entry_locks
                 WHERE work_entry_id = :entry_id AND user_id = :user_id AND session_id IS NULL'
            );
            $stmt->execute(['entry_id' => $entryId, 'user_id' => $userId]);
        } else {
            $stmt = $this->pdo->prepare(
                'DELETE FROM entry_locks
                 WHERE work_entry_id = :entry_id AND user_id = :user_id AND session_id = :session_id'
            );
            $stmt->execute([
                'entry_id'   => $entryId,
                'user_id'    => $userId,
                'session_id' => $sessionId,
            ]);
        }

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
