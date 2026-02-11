<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Arbeitsstunden-Eintrag (Antrag)
 */
class WorkEntry
{
    // Erlaubte Status-Übergänge gem. REQ-WF
    public const TRANSITIONS = [
        'entwurf' => ['eingereicht'],
        'eingereicht' => ['in_klaerung', 'freigegeben', 'abgelehnt', 'entwurf', 'storniert'],
        'in_klaerung' => ['freigegeben', 'abgelehnt', 'entwurf', 'storniert'],
        'storniert' => ['entwurf'],
        'freigegeben' => [],
        'abgelehnt' => [],
    ];

    // Status-Anzeigenamen
    public const STATUS_LABELS = [
        'entwurf' => 'Entwurf',
        'eingereicht' => 'Eingereicht',
        'in_klaerung' => 'In Klärung',
        'freigegeben' => 'Freigegeben',
        'abgelehnt' => 'Abgelehnt',
        'storniert' => 'Storniert',
    ];

    // Status-CSS-Klassen (Bootstrap)
    public const STATUS_BADGES = [
        'entwurf' => 'bg-secondary',
        'eingereicht' => 'bg-primary',
        'in_klaerung' => 'bg-warning text-dark',
        'freigegeben' => 'bg-success',
        'abgelehnt' => 'bg-danger',
        'storniert' => 'bg-dark',
    ];

    private ?int $id = null;
    private string $entryNumber = '';
    private int $userId = 0;
    private int $createdByUserId = 0;
    private ?int $categoryId = null;
    private string $workDate = '';
    private ?string $timeFrom = null;
    private ?string $timeTo = null;
    private float $hours = 0.0;
    private ?string $project = null;
    private ?string $description = null;
    private string $status = 'entwurf';
    private ?int $reviewedByUserId = null;
    private ?string $reviewedAt = null;
    private ?string $rejectionReason = null;
    private ?string $returnReason = null;
    private bool $isCorrected = false;
    private ?int $correctedByUserId = null;
    private ?string $correctedAt = null;
    private ?string $correctionReason = null;
    private ?float $originalHours = null;
    private ?string $submittedAt = null;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;
    private ?string $deletedAt = null;
    private int $version = 1;

    // Joins (optional, bei Bedarf befüllt)
    private ?string $userName = null;
    private ?string $createdByName = null;
    private ?string $categoryName = null;
    private ?string $reviewedByName = null;
    private int $openQuestionsCount = 0;

    /**
     * WorkEntry aus Datenbank-Array erstellen
     */
    public static function fromArray(array $data): self
    {
        $entry = new self();
        $entry->id = isset($data['id']) ? (int) $data['id'] : null;
        $entry->entryNumber = $data['entry_number'] ?? '';
        $entry->userId = (int) ($data['user_id'] ?? 0);
        $entry->createdByUserId = (int) ($data['created_by_user_id'] ?? 0);
        $entry->categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $entry->workDate = $data['work_date'] ?? '';
        $entry->timeFrom = $data['time_from'] ?? null;
        $entry->timeTo = $data['time_to'] ?? null;
        $entry->hours = (float) ($data['hours'] ?? 0);
        $entry->project = $data['project'] ?? null;
        $entry->description = $data['description'] ?? null;
        $entry->status = $data['status'] ?? 'entwurf';
        $entry->reviewedByUserId = isset($data['reviewed_by_user_id']) ? (int) $data['reviewed_by_user_id'] : null;
        $entry->reviewedAt = $data['reviewed_at'] ?? null;
        $entry->rejectionReason = $data['rejection_reason'] ?? null;
        $entry->returnReason = $data['return_reason'] ?? null;
        $entry->isCorrected = (bool) ($data['is_corrected'] ?? false);
        $entry->correctedByUserId = isset($data['corrected_by_user_id']) ? (int) $data['corrected_by_user_id'] : null;
        $entry->correctedAt = $data['corrected_at'] ?? null;
        $entry->correctionReason = $data['correction_reason'] ?? null;
        $entry->originalHours = isset($data['original_hours']) ? (float) $data['original_hours'] : null;
        $entry->submittedAt = $data['submitted_at'] ?? null;
        $entry->createdAt = $data['created_at'] ?? null;
        $entry->updatedAt = $data['updated_at'] ?? null;
        $entry->deletedAt = $data['deleted_at'] ?? null;
        $entry->version = (int) ($data['version'] ?? 1);

        // Join-Felder
        $entry->userName = $data['user_name'] ?? null;
        $entry->createdByName = $data['created_by_name'] ?? null;
        $entry->categoryName = $data['category_name'] ?? null;
        $entry->reviewedByName = $data['reviewed_by_name'] ?? null;
        $entry->openQuestionsCount = (int) ($data['open_questions_count'] ?? 0);

        return $entry;
    }

    // =========================================================================
    // Getter
    // =========================================================================

    public function getId(): ?int { return $this->id; }
    public function getEntryNumber(): string { return $this->entryNumber; }
    public function getUserId(): int { return $this->userId; }
    public function getCreatedByUserId(): int { return $this->createdByUserId; }
    public function getCategoryId(): ?int { return $this->categoryId; }
    public function getWorkDate(): string { return $this->workDate; }
    public function getTimeFrom(): ?string { return $this->timeFrom; }
    public function getTimeTo(): ?string { return $this->timeTo; }
    public function getHours(): float { return $this->hours; }
    public function getProject(): ?string { return $this->project; }
    public function getDescription(): ?string { return $this->description; }
    public function getStatus(): string { return $this->status; }
    public function getReviewedByUserId(): ?int { return $this->reviewedByUserId; }
    public function getReviewedAt(): ?string { return $this->reviewedAt; }
    public function getRejectionReason(): ?string { return $this->rejectionReason; }
    public function getReturnReason(): ?string { return $this->returnReason; }
    public function isCorrected(): bool { return $this->isCorrected; }
    public function getCorrectedByUserId(): ?int { return $this->correctedByUserId; }
    public function getCorrectedAt(): ?string { return $this->correctedAt; }
    public function getCorrectionReason(): ?string { return $this->correctionReason; }
    public function getOriginalHours(): ?float { return $this->originalHours; }
    public function getSubmittedAt(): ?string { return $this->submittedAt; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getDeletedAt(): ?string { return $this->deletedAt; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }
    public function getVersion(): int { return $this->version; }

    // Join-Felder
    public function getUserName(): ?string { return $this->userName; }
    public function getCreatedByName(): ?string { return $this->createdByName; }
    public function getCategoryName(): ?string { return $this->categoryName; }
    public function getReviewedByName(): ?string { return $this->reviewedByName; }
    public function getOpenQuestionsCount(): int { return $this->openQuestionsCount; }

    // =========================================================================
    // Status-Logik
    // =========================================================================

    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusBadge(): string
    {
        return self::STATUS_BADGES[$this->status] ?? 'bg-secondary';
    }

    /**
     * Prüft ob ein Statusübergang erlaubt ist
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = self::TRANSITIONS[$this->status] ?? [];
        return in_array($newStatus, $allowed, true);
    }

    /**
     * Ist ein Selbsteintrag?
     */
    public function isSelfEntry(): bool
    {
        return $this->userId === $this->createdByUserId;
    }

    /**
     * Ist bearbeitbar (nur im Status Entwurf)?
     */
    public function isEditable(): bool
    {
        return $this->status === 'entwurf';
    }

    /**
     * Kann eingereicht werden?
     */
    public function isSubmittable(): bool
    {
        return $this->status === 'entwurf';
    }

    /**
     * Kann zurückgezogen werden?
     */
    public function isWithdrawable(): bool
    {
        return in_array($this->status, ['eingereicht', 'in_klaerung'], true);
    }

    /**
     * Kann reaktiviert werden?
     */
    public function isReactivatable(): bool
    {
        return $this->status === 'storniert';
    }

    // =========================================================================
    // Setter
    // =========================================================================

    public function setStatus(string $status): void { $this->status = $status; }
    public function setReviewedByUserId(?int $id): void { $this->reviewedByUserId = $id; }
    public function setReviewedAt(?string $at): void { $this->reviewedAt = $at; }
    public function setRejectionReason(?string $reason): void { $this->rejectionReason = $reason; }
    public function setReturnReason(?string $reason): void { $this->returnReason = $reason; }
    public function setSubmittedAt(?string $at): void { $this->submittedAt = $at; }
}
