<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Edit-Session eines Nutzers an einem Event.
 *
 * Dokumentiert, dass ein Nutzer den Event-Editor geoeffnet hat — reine
 * UX-Information fuer Koordination zwischen Admins/Organisatoren.
 * KEIN Lock, KEINE Authorisation: die Integritaet wird durch den
 * Optimistic Lock aus Modul 6 I7e-B geschuetzt (event_tasks.version).
 *
 * VAES-Konvention: DATETIME-Felder werden als `?string` im 'Y-m-d H:i:s'-
 * Format gehalten (analog EventTask, WorkEntry usw.), nicht als
 * DateTimeImmutable.
 *
 * Eingefuehrt in Modul 6 I7e-C.1 Phase 1.
 */
final class EditSession
{
    private int $id = 0;
    private int $userId = 0;
    private int $eventId = 0;
    private string $browserSessionId = '';
    private string $startedAt = '';
    private string $lastSeenAt = '';
    private ?string $closedAt = null;

    public static function fromArray(array $data): self
    {
        $s = new self();
        $s->id               = (int) ($data['id'] ?? 0);
        $s->userId           = (int) ($data['user_id'] ?? 0);
        $s->eventId          = (int) ($data['event_id'] ?? 0);
        $s->browserSessionId = (string) ($data['browser_session_id'] ?? '');
        $s->startedAt        = (string) ($data['started_at'] ?? '');
        $s->lastSeenAt       = (string) ($data['last_seen_at'] ?? '');
        $s->closedAt         = $data['closed_at'] ?? null;
        return $s;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getEventId(): int
    {
        return $this->eventId;
    }

    public function getBrowserSessionId(): string
    {
        return $this->browserSessionId;
    }

    public function getStartedAt(): string
    {
        return $this->startedAt;
    }

    public function getLastSeenAt(): string
    {
        return $this->lastSeenAt;
    }

    public function getClosedAt(): ?string
    {
        return $this->closedAt;
    }

    public function isClosed(): bool
    {
        return $this->closedAt !== null;
    }
}
