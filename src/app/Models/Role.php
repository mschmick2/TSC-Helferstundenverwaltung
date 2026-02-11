<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Rollen-Modell
 */
class Role
{
    public const MITGLIED = 'mitglied';
    public const ERFASSER = 'erfasser';
    public const PRUEFER = 'pruefer';
    public const AUDITOR = 'auditor';
    public const ADMINISTRATOR = 'administrator';

    private ?int $id = null;
    private string $name = '';
    private ?string $description = null;

    public static function fromArray(array $data): self
    {
        $role = new self();
        $role->id = isset($data['id']) ? (int) $data['id'] : null;
        $role->name = $data['name'] ?? '';
        $role->description = $data['description'] ?? null;
        return $role;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Deutschsprachiger Anzeigename
     */
    public function getDisplayName(): string
    {
        return match ($this->name) {
            self::MITGLIED => 'Mitglied',
            self::ERFASSER => 'Erfasser',
            self::PRUEFER => 'Prüfer',
            self::AUDITOR => 'Auditor',
            self::ADMINISTRATOR => 'Administrator',
            default => $this->name,
        };
    }
}
