<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Veranstaltung (Event)
 *
 * Status-Lifecycle: entwurf -> veroeffentlicht -> abgeschlossen (oder abgesagt)
 */
class Event
{
    public const STATUS_ENTWURF          = 'entwurf';
    public const STATUS_VEROEFFENTLICHT  = 'veroeffentlicht';
    public const STATUS_ABGESCHLOSSEN    = 'abgeschlossen';
    public const STATUS_ABGESAGT         = 'abgesagt';

    public const ALL_STATUSES = [
        self::STATUS_ENTWURF,
        self::STATUS_VEROEFFENTLICHT,
        self::STATUS_ABGESCHLOSSEN,
        self::STATUS_ABGESAGT,
    ];

    /** Default-Vorlauf fuer Storno (Stunden vor Event-Start). */
    public const DEFAULT_CANCEL_DEADLINE_HOURS = 24;

    private ?int $id = null;
    private string $title = '';
    private ?string $description = null;
    private ?string $location = null;
    private string $startAt = '';
    private string $endAt = '';
    private string $status = self::STATUS_ENTWURF;
    private ?int $cancelDeadlineHours = self::DEFAULT_CANCEL_DEADLINE_HOURS;
    private int $createdBy = 0;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;
    private ?string $deletedAt = null;
    private ?int $deletedBy = null;

    public static function fromArray(array $data): self
    {
        $event = new self();
        $event->id                  = isset($data['id']) ? (int) $data['id'] : null;
        $event->title               = (string) ($data['title'] ?? '');
        $event->description         = $data['description'] ?? null;
        $event->location            = $data['location'] ?? null;
        $event->startAt             = (string) ($data['start_at'] ?? '');
        $event->endAt               = (string) ($data['end_at'] ?? '');
        $event->status              = (string) ($data['status'] ?? self::STATUS_ENTWURF);
        $event->cancelDeadlineHours = isset($data['cancel_deadline_hours'])
            ? (int) $data['cancel_deadline_hours']
            : self::DEFAULT_CANCEL_DEADLINE_HOURS;
        $event->createdBy           = (int) ($data['created_by'] ?? 0);
        $event->createdAt           = $data['created_at'] ?? null;
        $event->updatedAt           = $data['updated_at'] ?? null;
        $event->deletedAt           = $data['deleted_at'] ?? null;
        $event->deletedBy           = isset($data['deleted_by']) ? (int) $data['deleted_by'] : null;
        return $event;
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): ?string { return $this->description; }
    public function getLocation(): ?string { return $this->location; }
    public function getStartAt(): string { return $this->startAt; }
    public function getEndAt(): string { return $this->endAt; }
    public function getStatus(): string { return $this->status; }
    public function getCancelDeadlineHours(): ?int { return $this->cancelDeadlineHours; }
    public function getCreatedBy(): int { return $this->createdBy; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }
    public function getDeletedAt(): ?string { return $this->deletedAt; }
    public function getDeletedBy(): ?int { return $this->deletedBy; }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_VEROEFFENTLICHT;
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_ABGESCHLOSSEN, self::STATUS_ABGESAGT], true);
    }
}
