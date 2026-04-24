<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\EditSession;
use App\Models\EditSessionView;
use PDO;

/**
 * Repository fuer Edit-Sessions (Modul 6 I7e-C.1 Phase 1).
 *
 * Zentrale Invarianten:
 *   - heartbeat() und close() pruefen user_id in der WHERE-Klausel
 *     (IDOR-Schutz auf Repo-Ebene) und liefern `bool` zurueck: true,
 *     wenn genau eine Zeile betroffen war; sonst false (Session
 *     fehlt, ist geschlossen oder gehoert einem anderen User).
 *   - findActiveByEventId() betrachtet nur Sessions, deren
 *     `last_seen_at` innerhalb der letzten 2 Minuten liegt (Heartbeat-
 *     Timeout-Regel aus G1 K5) UND `closed_at` NULL ist.
 *   - create() ruft intern cleanupStale(), damit die Tabelle ohne Cron
 *     schlank bleibt (Architect-C3: Lazy-Cleanup statt Strato-Cron).
 */
class EditSessionRepository
{
    /**
     * Timeout-Grenzwert in Sekunden: Sessions, deren last_seen_at
     * aelter ist, gelten als inaktiv. 2 Minuten = 4 verpasste
     * Heartbeats bei 30 s Intervall.
     */
    public const ACTIVE_TIMEOUT_SECONDS = 120;

    /**
     * Lazy-Cleanup-Grenzwert: dangling Sessions (ohne closed_at),
     * deren last_seen_at aelter als 1 Stunde ist, werden beim
     * naechsten Session-Start entfernt.
     */
    public const STALE_CLEANUP_SECONDS = 3600;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Legt eine neue Edit-Session an. Vor dem INSERT laeuft der
     * Lazy-Cleanup, sodass geschlossene und veraltete Sessions die
     * Tabelle nicht anwachsen lassen.
     */
    public function create(int $userId, int $eventId, string $browserSessionId): int
    {
        $this->cleanupStale();

        $stmt = $this->pdo->prepare(
            "INSERT INTO edit_sessions
                (user_id, event_id, browser_session_id, started_at, last_seen_at)
             VALUES
                (:user_id, :event_id, :browser_session_id, NOW(), NOW())"
        );
        $stmt->execute([
            'user_id'            => $userId,
            'event_id'           => $eventId,
            'browser_session_id' => $browserSessionId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Aktualisiert `last_seen_at` fuer eine Session. Liefert true,
     * wenn genau eine Zeile betroffen war. Der zusaetzliche Filter
     * `user_id = :user_id` ist IDOR-Schutz auf Repo-Ebene — ein
     * fremder User kann keine Session eines anderen verlaengern.
     */
    public function heartbeat(int $sessionId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE edit_sessions
                SET last_seen_at = NOW()
              WHERE id = :id
                AND user_id = :user_id
                AND closed_at IS NULL"
        );
        $stmt->execute([
            'id'      => $sessionId,
            'user_id' => $userId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Schliesst eine Session (setzt closed_at). Analog heartbeat()
     * mit user_id-Filter als IDOR-Schutz. Idempotent: ein zweiter
     * Close liefert false (closed_at bereits gesetzt).
     */
    public function close(int $sessionId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE edit_sessions
                SET closed_at = NOW()
              WHERE id = :id
                AND user_id = :user_id
                AND closed_at IS NULL"
        );
        $stmt->execute([
            'id'      => $sessionId,
            'user_id' => $userId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Einzelne Session laden (primaer fuer Tests / Admin-Tools).
     */
    public function findById(int $sessionId): ?EditSession
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM edit_sessions WHERE id = :id"
        );
        $stmt->execute(['id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? EditSession::fromArray($row) : null;
    }

    /**
     * Aktive Sessions fuer ein Event — mit JOIN auf users fuer
     * Vorname/Nachname. Liefert EditSessionView-DTOs, damit der
     * Controller keinen zweiten User-Lookup braucht.
     *
     * "Aktiv" = closed_at IS NULL UND last_seen_at innerhalb der
     * letzten ACTIVE_TIMEOUT_SECONDS.
     *
     * Reihenfolge: aelteste zuerst (started_at ASC). So steht der
     * Nutzer, der zuerst kam, oben — die UX-Anzeige "X hat seit
     * Y Minuten offen" bleibt stabil sortiert.
     *
     * @return EditSessionView[]
     */
    public function findActiveByEventId(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT
                es.id                 AS session_id,
                es.user_id            AS user_id,
                u.vorname             AS vorname,
                u.nachname            AS nachname,
                es.started_at         AS started_at,
                es.last_seen_at       AS last_seen_at,
                TIMESTAMPDIFF(SECOND, es.started_at, NOW()) AS duration_seconds
             FROM edit_sessions es
             INNER JOIN users u ON u.id = es.user_id
             WHERE es.event_id = :event_id
               AND es.closed_at IS NULL
               AND es.last_seen_at >= (NOW() - INTERVAL :timeout SECOND)
             ORDER BY es.started_at ASC, es.id ASC"
        );
        $stmt->bindValue('event_id', $eventId, PDO::PARAM_INT);
        $stmt->bindValue('timeout', self::ACTIVE_TIMEOUT_SECONDS, PDO::PARAM_INT);
        $stmt->execute();

        $views = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $views[] = new EditSessionView(
                (int) $row['session_id'],
                (int) $row['user_id'],
                (string) ($row['vorname'] ?? ''),
                (string) ($row['nachname'] ?? ''),
                (string) $row['started_at'],
                (string) $row['last_seen_at'],
                (int) $row['duration_seconds'],
            );
        }
        return $views;
    }

    /**
     * Lazy-Cleanup: entfernt geschlossene Sessions sofort und
     * dangling Sessions (ohne Close), deren last_seen_at aelter als
     * STALE_CLEANUP_SECONDS ist. Keine Cron-Abhaengigkeit (Strato
     * hat keinen Cron; Architect-C3).
     *
     * Wird intern von create() aufgerufen. Kann aber auch manuell
     * aus Admin-Tools getriggert werden.
     *
     * @return int Anzahl geloeschter Zeilen.
     */
    public function cleanupStale(): int
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM edit_sessions
              WHERE closed_at IS NOT NULL
                 OR last_seen_at < (NOW() - INTERVAL :cutoff SECOND)"
        );
        $stmt->bindValue('cutoff', self::STALE_CLEANUP_SECONDS, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }
}
