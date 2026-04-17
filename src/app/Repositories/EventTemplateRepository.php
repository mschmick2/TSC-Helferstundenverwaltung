<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\EventTemplate;
use App\Models\EventTemplateTask;
use PDO;

/**
 * Repository fuer Event-Templates mit Versionierung.
 *
 * Policy (siehe §7 Requirements):
 *   - Neue Version: parent_template_id = alte_id, alte Version is_current=0
 *   - findCurrent() liefert nur is_current=1 + deleted_at IS NULL
 *   - saveAsNewVersion() erledigt die Versionierungs-Mechanik atomar
 */
class EventTemplateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Liste aller aktuellen Template-Versionen.
     *
     * @return EventTemplate[]
     */
    public function findCurrent(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM event_templates
             WHERE is_current = 1 AND deleted_at IS NULL
             ORDER BY name ASC"
        );

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = EventTemplate::fromArray($row);
        }
        return $rows;
    }

    public function findById(int $id): ?EventTemplate
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM event_templates WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? EventTemplate::fromArray($row) : null;
    }

    /**
     * Alle Versionen eines Templates (ueber parent-Kette ermittelt).
     *
     * @return EventTemplate[]
     */
    public function findAllVersionsByRoot(int $rootId): array
    {
        // Recursive CTE: alle Nachfolger von rootId
        $stmt = $this->pdo->prepare(
            "WITH RECURSIVE tpl_tree AS (
                SELECT * FROM event_templates WHERE id = :root
                UNION ALL
                SELECT et.* FROM event_templates et
                    JOIN tpl_tree tt ON et.parent_template_id = tt.id
             )
             SELECT * FROM tpl_tree WHERE deleted_at IS NULL ORDER BY version ASC"
        );
        $stmt->execute(['root' => $rootId]);

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = EventTemplate::fromArray($row);
        }
        return $rows;
    }

    /**
     * Task-Vorlagen eines Templates.
     *
     * @return EventTemplateTask[]
     */
    public function findTasksByTemplate(int $templateId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM event_template_tasks
             WHERE template_id = :tid
             ORDER BY sort_order ASC"
        );
        $stmt->execute(['tid' => $templateId]);

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = EventTemplateTask::fromArray($row);
        }
        return $rows;
    }

    /**
     * Neues Top-Level-Template (keine Vorgaenger-Version).
     */
    public function createInitial(string $name, ?string $description, int $createdBy): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO event_templates (name, description, version, is_current, created_by)
             VALUES (:name, :description, 1, 1, :created_by)"
        );
        $stmt->execute([
            'name' => $name,
            'description' => $description,
            'created_by' => $createdBy,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Neue Version eines Templates anlegen (parent bekommt is_current=0).
     *
     * Tasks muessen nach dem Call mit addTask() separat kopiert/ergaenzt werden.
     */
    public function saveAsNewVersion(
        int $parentId,
        string $name,
        ?string $description,
        int $createdBy
    ): int {
        $this->pdo->beginTransaction();
        try {
            // Parent-Info holen
            $parent = $this->findById($parentId);
            if ($parent === null) {
                throw new \RuntimeException("Parent-Template #$parentId nicht gefunden.");
            }

            // Parent deaktivieren
            $stmt = $this->pdo->prepare(
                "UPDATE event_templates SET is_current = 0 WHERE id = :id"
            );
            $stmt->execute(['id' => $parentId]);

            // Neue Version anlegen
            $newVersion = $parent->getVersion() + 1;
            $stmt = $this->pdo->prepare(
                "INSERT INTO event_templates
                 (name, description, version, parent_template_id, is_current, created_by)
                 VALUES
                 (:name, :description, :version, :parent, 1, :created_by)"
            );
            $stmt->execute([
                'name' => $name,
                'description' => $description,
                'version' => $newVersion,
                'parent' => $parentId,
                'created_by' => $createdBy,
            ]);

            $newId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return $newId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function addTask(int $templateId, array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO event_template_tasks
             (template_id, category_id, title, description, task_type, slot_mode,
              default_offset_minutes_start, default_offset_minutes_end,
              capacity_mode, capacity_target, hours_default, sort_order)
             VALUES
             (:template_id, :category_id, :title, :description, :task_type, :slot_mode,
              :off_start, :off_end, :capacity_mode, :capacity_target, :hours_default, :sort_order)"
        );
        $stmt->execute([
            'template_id' => $templateId,
            'category_id' => $data['category_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'task_type' => $data['task_type'] ?? 'aufgabe',
            'slot_mode' => $data['slot_mode'] ?? 'fix',
            'off_start' => $data['default_offset_minutes_start'] ?? null,
            'off_end' => $data['default_offset_minutes_end'] ?? null,
            'capacity_mode' => $data['capacity_mode'] ?? 'unbegrenzt',
            'capacity_target' => $data['capacity_target'] ?? null,
            'hours_default' => $data['hours_default'] ?? 0.0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function softDelete(int $templateId, int $deletedBy): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_templates
             SET deleted_at = NOW(), deleted_by = :user, is_current = 0
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['user' => $deletedBy, 'id' => $templateId]);
        return $stmt->rowCount() > 0;
    }
}
