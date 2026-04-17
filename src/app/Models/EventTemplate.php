<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Event-Template (versioniert)
 *
 * Bei Aenderung wird eine NEUE Version angelegt (parent_template_id zeigt auf
 * die alte), die alte Version bekommt is_current=0.
 */
class EventTemplate
{
    private ?int $id = null;
    private string $name = '';
    private ?string $description = null;
    private int $version = 1;
    private ?int $parentTemplateId = null;
    private bool $isCurrent = true;
    private int $createdBy = 0;
    private ?string $createdAt = null;
    private ?string $deletedAt = null;

    public static function fromArray(array $data): self
    {
        $t = new self();
        $t->id                = isset($data['id']) ? (int) $data['id'] : null;
        $t->name              = (string) ($data['name'] ?? '');
        $t->description       = $data['description'] ?? null;
        $t->version           = (int) ($data['version'] ?? 1);
        $t->parentTemplateId  = isset($data['parent_template_id']) ? (int) $data['parent_template_id'] : null;
        $t->isCurrent         = (bool) ($data['is_current'] ?? true);
        $t->createdBy         = (int) ($data['created_by'] ?? 0);
        $t->createdAt         = $data['created_at'] ?? null;
        $t->deletedAt         = $data['deleted_at'] ?? null;
        return $t;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getDescription(): ?string { return $this->description; }
    public function getVersion(): int { return $this->version; }
    public function getParentTemplateId(): ?int { return $this->parentTemplateId; }
    public function isCurrent(): bool { return $this->isCurrent; }
    public function getCreatedBy(): int { return $this->createdBy; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getDeletedAt(): ?string { return $this->deletedAt; }
}
