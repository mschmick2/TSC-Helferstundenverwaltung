<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Category;

/**
 * Repository für Tätigkeitskategorien
 */
class CategoryRepository
{
    public function __construct(
        private \PDO $pdo
    ) {
    }

    /**
     * Alle aktiven Kategorien (für Dropdown)
     *
     * @return Category[]
     */
    public function findAllActive(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM categories
             WHERE is_active = TRUE AND deleted_at IS NULL
             ORDER BY name ASC"
        );

        $categories = [];
        while ($row = $stmt->fetch()) {
            $categories[] = Category::fromArray($row);
        }
        return $categories;
    }

    /**
     * Kategorie anhand ID finden
     */
    public function findById(int $id): ?Category
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM categories WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch();

        return $data !== false ? Category::fromArray($data) : null;
    }

    // =========================================================================
    // Admin-Methoden
    // =========================================================================

    /**
     * Alle Kategorien für Admin (inkl. inaktive, ohne soft-deleted)
     *
     * @return Category[]
     */
    public function findAllForAdmin(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM categories WHERE deleted_at IS NULL
             ORDER BY name ASC"
        );

        $categories = [];
        while ($row = $stmt->fetch()) {
            $categories[] = Category::fromArray($row);
        }
        return $categories;
    }

    /**
     * Kategorie anhand ID finden (inkl. inaktive, für Admin)
     */
    public function findByIdForAdmin(int $id): ?Category
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM categories WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch();

        return $data !== false ? Category::fromArray($data) : null;
    }

    /**
     * Rohdaten für Audit-Trail
     */
    public function getRawById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM categories WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch();
        return $data !== false ? $data : null;
    }

    /**
     * Neue Kategorie erstellen
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO categories (name, description, color, sort_order, is_active, is_contribution)
             VALUES (:name, :description, :color, :sort_order, :is_active, :is_contribution)"
        );
        $stmt->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'color' => $data['color'] ?? \App\Models\Category::DEFAULT_COLOR,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'is_contribution' => isset($data['is_contribution']) ? (int) $data['is_contribution'] : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Kategorie aktualisieren
     */
    public function update(int $id, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE categories
             SET name = :name, description = :description, color = :color,
                 sort_order = :sort_order, is_active = :is_active,
                 is_contribution = :is_contribution
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'color' => $data['color'] ?? \App\Models\Category::DEFAULT_COLOR,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (int) ($data['is_active'] ?? 1),
            'is_contribution' => (int) ($data['is_contribution'] ?? 0),
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Nur Beigabe-Kategorien (fuer Event-Task-Dropdown)
     *
     * @return Category[]
     */
    public function findContributions(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM categories
             WHERE is_contribution = TRUE AND is_active = TRUE AND deleted_at IS NULL
             ORDER BY name ASC"
        );

        $categories = [];
        while ($row = $stmt->fetch()) {
            $categories[] = Category::fromArray($row);
        }
        return $categories;
    }

    /**
     * Kategorie soft-löschen
     */
    public function softDelete(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE categories SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Kategorie aktivieren
     */
    public function activate(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE categories SET is_active = TRUE WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Kategorie deaktivieren
     */
    public function deactivate(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE categories SET is_active = FALSE WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Sortierreihenfolge aktualisieren
     */
    public function updateSortOrder(int $id, int $sortOrder): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE categories SET sort_order = :sort_order WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['sort_order' => $sortOrder, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Anzahl der Einträge für eine Kategorie zählen
     */
    public function countEntriesForCategory(int $id): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM work_entries WHERE category_id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }
}
