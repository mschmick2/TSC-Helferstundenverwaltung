<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Tätigkeitskategorie
 */
class Category
{
    public const DEFAULT_COLOR = '#0d6efd';

    private ?int $id = null;
    private string $name = '';
    private ?string $description = null;
    private string $color = self::DEFAULT_COLOR;
    private int $sortOrder = 0;
    private bool $isActive = true;
    private bool $isContribution = false;
    private ?string $deletedAt = null;

    public static function fromArray(array $data): self
    {
        $cat = new self();
        $cat->id = isset($data['id']) ? (int) $data['id'] : null;
        $cat->name = $data['name'] ?? '';
        $cat->description = $data['description'] ?? null;
        $cat->color = (string) ($data['color'] ?? self::DEFAULT_COLOR);
        $cat->sortOrder = (int) ($data['sort_order'] ?? 0);
        $cat->isActive = (bool) ($data['is_active'] ?? true);
        $cat->isContribution = (bool) ($data['is_contribution'] ?? false);
        $cat->deletedAt = $data['deleted_at'] ?? null;
        return $cat;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getColor(): string { return $this->color; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function isActive(): bool { return $this->isActive; }
    public function isContribution(): bool { return $this->isContribution; }
    public function getDeletedAt(): ?string { return $this->deletedAt; }
}
