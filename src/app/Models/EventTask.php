<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Event-Aufgabe (Task oder Beigabe)
 */
class EventTask
{
    public const TYPE_AUFGABE = 'aufgabe';
    public const TYPE_BEIGABE = 'beigabe';

    public const SLOT_FIX      = 'fix';
    public const SLOT_VARIABEL = 'variabel';

    public const CAP_UNBEGRENZT = 'unbegrenzt';
    public const CAP_ZIEL       = 'ziel';
    public const CAP_MAXIMUM    = 'maximum';

    private ?int $id = null;
    private int $eventId = 0;
    private ?int $parentTaskId = null;
    private bool $isGroup = false;
    private ?int $categoryId = null;
    private string $title = '';
    private ?string $description = null;
    private string $taskType = self::TYPE_AUFGABE;
    private ?string $slotMode = self::SLOT_FIX;
    private ?string $startAt = null;
    private ?string $endAt = null;
    private string $capacityMode = self::CAP_UNBEGRENZT;
    private ?int $capacityTarget = null;
    private float $hoursDefault = 0.0;
    private int $sortOrder = 0;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;
    private ?string $deletedAt = null;
    private ?int $deletedBy = null;
    private int $version = 1;

    public static function fromArray(array $data): self
    {
        $t = new self();
        $t->id              = isset($data['id']) ? (int) $data['id'] : null;
        $t->eventId         = (int) ($data['event_id'] ?? 0);
        $t->parentTaskId    = isset($data['parent_task_id']) && $data['parent_task_id'] !== null
            ? (int) $data['parent_task_id'] : null;
        $t->isGroup         = isset($data['is_group']) && (int) $data['is_group'] === 1;
        $t->categoryId      = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $t->title           = (string) ($data['title'] ?? '');
        $t->description     = $data['description'] ?? null;
        $t->taskType        = (string) ($data['task_type'] ?? self::TYPE_AUFGABE);
        if (array_key_exists('slot_mode', $data)) {
            $t->slotMode = $data['slot_mode'] !== null ? (string) $data['slot_mode'] : null;
        } else {
            $t->slotMode = self::SLOT_FIX;
        }
        $t->startAt         = $data['start_at'] ?? null;
        $t->endAt           = $data['end_at'] ?? null;
        $t->capacityMode    = (string) ($data['capacity_mode'] ?? self::CAP_UNBEGRENZT);
        $t->capacityTarget  = isset($data['capacity_target']) ? (int) $data['capacity_target'] : null;
        $t->hoursDefault    = (float) ($data['hours_default'] ?? 0.0);
        $t->sortOrder       = (int) ($data['sort_order'] ?? 0);
        $t->createdAt       = $data['created_at'] ?? null;
        $t->updatedAt       = $data['updated_at'] ?? null;
        $t->deletedAt       = $data['deleted_at'] ?? null;
        $t->deletedBy       = isset($data['deleted_by']) ? (int) $data['deleted_by'] : null;
        $t->version         = isset($data['version']) ? (int) $data['version'] : 1;
        return $t;
    }

    public function getId(): ?int { return $this->id; }
    public function getEventId(): int { return $this->eventId; }
    public function getParentTaskId(): ?int { return $this->parentTaskId; }
    public function isGroup(): bool { return $this->isGroup; }
    public function getCategoryId(): ?int { return $this->categoryId; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): ?string { return $this->description; }
    public function getTaskType(): string { return $this->taskType; }
    public function getSlotMode(): ?string { return $this->slotMode; }
    public function getStartAt(): ?string { return $this->startAt; }
    public function getEndAt(): ?string { return $this->endAt; }
    public function getCapacityMode(): string { return $this->capacityMode; }
    public function getCapacityTarget(): ?int { return $this->capacityTarget; }
    public function getHoursDefault(): float { return $this->hoursDefault; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }
    public function getDeletedAt(): ?string { return $this->deletedAt; }
    public function getVersion(): int { return $this->version; }

    public function isContribution(): bool
    {
        return $this->taskType === self::TYPE_BEIGABE;
    }

    public function hasFixedSlot(): bool
    {
        return $this->slotMode === self::SLOT_FIX;
    }
}
