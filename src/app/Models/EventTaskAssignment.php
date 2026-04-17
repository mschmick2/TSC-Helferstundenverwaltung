<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Zusage eines Mitglieds fuer eine Event-Aufgabe.
 */
class EventTaskAssignment
{
    public const STATUS_VORGESCHLAGEN     = 'vorgeschlagen';
    public const STATUS_BESTAETIGT        = 'bestaetigt';
    public const STATUS_STORNO_ANGEFRAGT  = 'storno_angefragt';
    public const STATUS_STORNIERT         = 'storniert';
    public const STATUS_ABGESCHLOSSEN     = 'abgeschlossen';

    private ?int $id = null;
    private int $taskId = 0;
    private int $userId = 0;
    private string $status = self::STATUS_VORGESCHLAGEN;
    private ?string $proposedStart = null;
    private ?string $proposedEnd = null;
    private ?float $actualHours = null;
    private ?int $replacementSuggestedUserId = null;
    private ?int $workEntryId = null;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;
    private ?string $deletedAt = null;
    private ?int $deletedBy = null;

    public static function fromArray(array $data): self
    {
        $a = new self();
        $a->id                           = isset($data['id']) ? (int) $data['id'] : null;
        $a->taskId                       = (int) ($data['task_id'] ?? 0);
        $a->userId                       = (int) ($data['user_id'] ?? 0);
        $a->status                       = (string) ($data['status'] ?? self::STATUS_VORGESCHLAGEN);
        $a->proposedStart                = $data['proposed_start'] ?? null;
        $a->proposedEnd                  = $data['proposed_end'] ?? null;
        $a->actualHours                  = isset($data['actual_hours']) ? (float) $data['actual_hours'] : null;
        $a->replacementSuggestedUserId   = isset($data['replacement_suggested_user_id'])
            ? (int) $data['replacement_suggested_user_id'] : null;
        $a->workEntryId                  = isset($data['work_entry_id']) ? (int) $data['work_entry_id'] : null;
        $a->createdAt                    = $data['created_at'] ?? null;
        $a->updatedAt                    = $data['updated_at'] ?? null;
        $a->deletedAt                    = $data['deleted_at'] ?? null;
        $a->deletedBy                    = isset($data['deleted_by']) ? (int) $data['deleted_by'] : null;
        return $a;
    }

    public function getId(): ?int { return $this->id; }
    public function getTaskId(): int { return $this->taskId; }
    public function getUserId(): int { return $this->userId; }
    public function getStatus(): string { return $this->status; }
    public function getProposedStart(): ?string { return $this->proposedStart; }
    public function getProposedEnd(): ?string { return $this->proposedEnd; }
    public function getActualHours(): ?float { return $this->actualHours; }
    public function getReplacementSuggestedUserId(): ?int { return $this->replacementSuggestedUserId; }
    public function getWorkEntryId(): ?int { return $this->workEntryId; }
    public function getCreatedAt(): ?string { return $this->createdAt; }

    public function isActive(): bool
    {
        return in_array($this->status, [
            self::STATUS_VORGESCHLAGEN,
            self::STATUS_BESTAETIGT,
            self::STATUS_STORNO_ANGEFRAGT,
        ], true);
    }
}
