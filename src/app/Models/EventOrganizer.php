<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Event-Organizer-Zuordnung (n:m users <-> events)
 */
class EventOrganizer
{
    private int $eventId = 0;
    private int $userId = 0;
    private ?string $assignedAt = null;
    private ?int $assignedBy = null;

    public static function fromArray(array $data): self
    {
        $o = new self();
        $o->eventId    = (int) ($data['event_id'] ?? 0);
        $o->userId     = (int) ($data['user_id'] ?? 0);
        $o->assignedAt = $data['assigned_at'] ?? null;
        $o->assignedBy = isset($data['assigned_by']) ? (int) $data['assigned_by'] : null;
        return $o;
    }

    public function getEventId(): int { return $this->eventId; }
    public function getUserId(): int { return $this->userId; }
    public function getAssignedAt(): ?string { return $this->assignedAt; }
    public function getAssignedBy(): ?int { return $this->assignedBy; }
}
