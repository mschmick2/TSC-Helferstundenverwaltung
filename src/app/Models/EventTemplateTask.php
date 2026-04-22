<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Template-Task: Task-Vorlage innerhalb eines Event-Templates.
 *
 * Zeitfenster werden als Offset zum Event-Start gespeichert (Minuten),
 * damit das Template wiederverwendbar bleibt.
 */
class EventTemplateTask
{
    private ?int $id = null;
    private int $templateId = 0;
    private ?int $parentTemplateTaskId = null;
    private bool $isGroup = false;
    private ?int $categoryId = null;
    private string $title = '';
    private ?string $description = null;
    private string $taskType = EventTask::TYPE_AUFGABE;
    private ?string $slotMode = EventTask::SLOT_FIX;
    private ?int $defaultOffsetMinutesStart = null;
    private ?int $defaultOffsetMinutesEnd = null;
    private string $capacityMode = EventTask::CAP_UNBEGRENZT;
    private ?int $capacityTarget = null;
    private float $hoursDefault = 0.0;
    private int $sortOrder = 0;

    public static function fromArray(array $data): self
    {
        $t = new self();
        $t->id                        = isset($data['id']) ? (int) $data['id'] : null;
        $t->templateId                = (int) ($data['template_id'] ?? 0);
        $t->parentTemplateTaskId      = isset($data['parent_template_task_id']) && $data['parent_template_task_id'] !== null
            ? (int) $data['parent_template_task_id'] : null;
        $t->isGroup                   = isset($data['is_group']) && (int) $data['is_group'] === 1;
        $t->categoryId                = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $t->title                     = (string) ($data['title'] ?? '');
        $t->description               = $data['description'] ?? null;
        $t->taskType                  = (string) ($data['task_type'] ?? EventTask::TYPE_AUFGABE);
        if (array_key_exists('slot_mode', $data)) {
            $t->slotMode = $data['slot_mode'] !== null ? (string) $data['slot_mode'] : null;
        } else {
            $t->slotMode = EventTask::SLOT_FIX;
        }
        $t->defaultOffsetMinutesStart = isset($data['default_offset_minutes_start'])
            ? (int) $data['default_offset_minutes_start'] : null;
        $t->defaultOffsetMinutesEnd   = isset($data['default_offset_minutes_end'])
            ? (int) $data['default_offset_minutes_end'] : null;
        $t->capacityMode              = (string) ($data['capacity_mode'] ?? EventTask::CAP_UNBEGRENZT);
        $t->capacityTarget            = isset($data['capacity_target']) ? (int) $data['capacity_target'] : null;
        $t->hoursDefault              = (float) ($data['hours_default'] ?? 0.0);
        $t->sortOrder                 = (int) ($data['sort_order'] ?? 0);
        return $t;
    }

    public function getId(): ?int { return $this->id; }
    public function getTemplateId(): int { return $this->templateId; }
    public function getParentTemplateTaskId(): ?int { return $this->parentTemplateTaskId; }
    public function isGroup(): bool { return $this->isGroup; }
    public function getCategoryId(): ?int { return $this->categoryId; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): ?string { return $this->description; }
    public function getTaskType(): string { return $this->taskType; }
    public function getSlotMode(): ?string { return $this->slotMode; }
    public function getDefaultOffsetMinutesStart(): ?int { return $this->defaultOffsetMinutesStart; }
    public function getDefaultOffsetMinutesEnd(): ?int { return $this->defaultOffsetMinutesEnd; }
    public function getCapacityMode(): string { return $this->capacityMode; }
    public function getCapacityTarget(): ?int { return $this->capacityTarget; }
    public function getHoursDefault(): float { return $this->hoursDefault; }
    public function getSortOrder(): int { return $this->sortOrder; }
}
