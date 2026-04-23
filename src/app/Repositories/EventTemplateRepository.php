<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\TreeWalkLimits;
use App\Models\EventTemplate;
use App\Models\EventTemplateTask;
use PDO;

/**
 * Repository fuer Event-Templates mit Versionierung.
 *
 * Policy (siehe §7 Requirements):
 *   - Neue Version: parent_template_id = alte_id, alte Version is_current=0
 *   - findCurrent() liefert nur is_current=1 + deleted_at IS NULL
 *   - saveAsNewVersion() liefert die Versionierungs-Mechanik; Transaction
 *     liegt in der Verantwortung des aufrufenden Service (siehe G3-Finding I4)
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
     * KEINE eigene Transaction - der aufrufende Service ist fuer
     * Transaction-Grenzen verantwortlich (Layering).
     * Tasks muessen nach dem Call separat kopiert/ergaenzt werden.
     */
    public function saveAsNewVersion(
        int $parentId,
        string $name,
        ?string $description,
        int $createdBy
    ): int {
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

        return (int) $this->pdo->lastInsertId();
    }

    public function addTask(int $templateId, array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO event_template_tasks
             (template_id, parent_template_task_id, is_group,
              category_id, title, description, task_type, slot_mode,
              default_offset_minutes_start, default_offset_minutes_end,
              capacity_mode, capacity_target, hours_default, sort_order)
             VALUES
             (:template_id, :parent_id, :is_group,
              :category_id, :title, :description, :task_type, :slot_mode,
              :off_start, :off_end, :capacity_mode, :capacity_target, :hours_default, :sort_order)"
        );
        $stmt->execute([
            'template_id' => $templateId,
            'parent_id'   => $data['parent_template_task_id'] ?? null,
            'is_group'    => isset($data['is_group']) && $data['is_group'] ? 1 : 0,
            'category_id' => $data['category_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'task_type' => $data['task_type'] ?? 'aufgabe',
            // slot_mode null-aware: explizites null (Gruppe) bleibt erhalten
            'slot_mode' => array_key_exists('slot_mode', $data) ? $data['slot_mode'] : 'fix',
            'off_start' => $data['default_offset_minutes_start'] ?? null,
            'off_end' => $data['default_offset_minutes_end'] ?? null,
            'capacity_mode' => $data['capacity_mode'] ?? 'unbegrenzt',
            'capacity_target' => $data['capacity_target'] ?? null,
            'hours_default' => $data['hours_default'] ?? 0.0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Aktive Geschwister-Vorlagen einer Ebene im Template-Baum.
     *
     * @return EventTemplateTask[]
     */
    public function findTaskChildren(int $templateId, ?int $parentTaskId): array
    {
        if ($parentTaskId === null) {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM event_template_tasks
                 WHERE template_id = :tid AND parent_template_task_id IS NULL
                 ORDER BY sort_order ASC, id ASC"
            );
            $stmt->execute(['tid' => $templateId]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM event_template_tasks
                 WHERE template_id = :tid AND parent_template_task_id = :pid
                 ORDER BY sort_order ASC, id ASC"
            );
            $stmt->execute(['tid' => $templateId, 'pid' => $parentTaskId]);
        }
        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = EventTemplateTask::fromArray($row);
        }
        return $rows;
    }

    public function countActiveTaskChildren(int $templateTaskId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM event_template_tasks
             WHERE parent_template_task_id = :pid"
        );
        $stmt->execute(['pid' => $templateTaskId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Tiefe einer Template-Vorlage relativ zur Wurzel (Top-Level = 0).
     */
    public function getTaskDepth(int $templateTaskId): int
    {
        $depth = 0;
        $current = $templateTaskId;
        for ($i = 0; $i < TreeWalkLimits::SAFETY_DEPTH_CAP; $i++) {
            $stmt = $this->pdo->prepare(
                "SELECT parent_template_task_id FROM event_template_tasks WHERE id = :id"
            );
            $stmt->execute(['id' => $current]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || $row['parent_template_task_id'] === null) {
                return $depth;
            }
            $current = (int) $row['parent_template_task_id'];
            $depth++;
        }
        return $depth;
    }

    public function isTaskDescendantOf(int $taskId, int $candidateAncestor): bool
    {
        $current = $taskId;
        for ($i = 0; $i < TreeWalkLimits::SAFETY_DEPTH_CAP; $i++) {
            $stmt = $this->pdo->prepare(
                "SELECT parent_template_task_id FROM event_template_tasks WHERE id = :id"
            );
            $stmt->execute(['id' => $current]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || $row['parent_template_task_id'] === null) {
                return false;
            }
            $parent = (int) $row['parent_template_task_id'];
            if ($parent === $candidateAncestor) {
                return true;
            }
            $current = $parent;
        }
        return false;
    }

    public function moveTask(int $templateTaskId, ?int $newParentId, int $newSortOrder): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_template_tasks
             SET parent_template_task_id = :pid, sort_order = :ord
             WHERE id = :id"
        );
        $stmt->execute([
            'pid' => $newParentId,
            'ord' => $newSortOrder,
            'id'  => $templateTaskId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function convertTaskToGroup(int $templateTaskId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_template_tasks
             SET is_group = 1,
                 slot_mode = NULL,
                 default_offset_minutes_start = NULL,
                 default_offset_minutes_end = NULL,
                 capacity_mode = 'unbegrenzt',
                 capacity_target = NULL,
                 hours_default = 0,
                 task_type = 'aufgabe'
             WHERE id = :id"
        );
        $stmt->execute(['id' => $templateTaskId]);
        return $stmt->rowCount() > 0;
    }

    public function convertTaskToLeaf(int $templateTaskId, array $leafData): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_template_tasks
             SET is_group = 0,
                 task_type = :task_type,
                 slot_mode = :slot_mode,
                 default_offset_minutes_start = :off_start,
                 default_offset_minutes_end = :off_end,
                 capacity_mode = :capacity_mode,
                 capacity_target = :capacity_target,
                 hours_default = :hours_default
             WHERE id = :id"
        );
        $stmt->execute([
            'task_type'       => $leafData['task_type'] ?? 'aufgabe',
            'slot_mode'       => $leafData['slot_mode'] ?? 'fix',
            'off_start'       => $leafData['default_offset_minutes_start'] ?? null,
            'off_end'         => $leafData['default_offset_minutes_end'] ?? null,
            'capacity_mode'   => $leafData['capacity_mode'] ?? 'unbegrenzt',
            'capacity_target' => $leafData['capacity_target'] ?? null,
            'hours_default'   => $leafData['hours_default'] ?? 0.0,
            'id'              => $templateTaskId,
        ]);
        return $stmt->rowCount() > 0;
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

    public function findTaskById(int $taskId): ?EventTemplateTask
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM event_template_tasks WHERE id = :id"
        );
        $stmt->execute(['id' => $taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? EventTemplateTask::fromArray($row) : null;
    }

    public function updateTask(int $taskId, array $data): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_template_tasks SET
                category_id = :category_id,
                title = :title,
                description = :description,
                task_type = :task_type,
                slot_mode = :slot_mode,
                default_offset_minutes_start = :off_start,
                default_offset_minutes_end = :off_end,
                capacity_mode = :capacity_mode,
                capacity_target = :capacity_target,
                hours_default = :hours_default,
                sort_order = :sort_order
             WHERE id = :id"
        );
        $stmt->execute([
            'category_id' => $data['category_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'task_type' => $data['task_type'] ?? 'aufgabe',
            // null-aware: explizites null (Gruppe) bleibt erhalten
            'slot_mode' => array_key_exists('slot_mode', $data) ? $data['slot_mode'] : 'fix',
            'off_start' => $data['default_offset_minutes_start'] ?? null,
            'off_end' => $data['default_offset_minutes_end'] ?? null,
            'capacity_mode' => $data['capacity_mode'] ?? 'unbegrenzt',
            'capacity_target' => $data['capacity_target'] ?? null,
            'hours_default' => $data['hours_default'] ?? 0.0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'id' => $taskId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function deleteTask(int $taskId): bool
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM event_template_tasks WHERE id = :id"
        );
        $stmt->execute(['id' => $taskId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Sort-Order aller Tasks eines Templates setzen.
     *
     * @param int[] $orderedTaskIds Task-IDs in gewuenschter Reihenfolge
     */
    public function reorderTasks(int $templateId, array $orderedTaskIds): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_template_tasks
             SET sort_order = :ord
             WHERE id = :id AND template_id = :tid"
        );
        foreach (array_values($orderedTaskIds) as $index => $taskId) {
            $stmt->execute([
                'ord' => $index,
                'id'  => (int) $taskId,
                'tid' => $templateId,
            ]);
        }
    }

    /**
     * Pruefung: wurden aus einer beliebigen Version dieses Template-Root
     * bereits Events abgeleitet?
     */
    public function hasDerivedEvents(int $templateId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM events
             WHERE source_template_id = :tid AND deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute(['tid' => $templateId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Alle Tasks einer Template-Version in ein Ziel-Template kopieren —
     * inklusive Hierarchie. Eine flache Quell-Tabelle (alle parent_template_task_id
     * NULL) wird in einer Schicht kopiert; bei Baumstrukturen werden die
     * neuen parent-IDs Schicht fuer Schicht aufgebaut.
     *
     * Wird von saveAsNewVersion verwendet. Liefert die Anzahl kopierter Knoten.
     */
    public function copyTasks(int $fromTemplateId, int $toTemplateId): int
    {
        return $this->copyTaskSubtree($fromTemplateId, $toTemplateId, null, null);
    }

    private function copyTaskSubtree(
        int $fromTemplateId,
        int $toTemplateId,
        ?int $oldParentId,
        ?int $newParentId
    ): int {
        $children = $this->findTaskChildren($fromTemplateId, $oldParentId);
        $count = 0;
        foreach ($children as $child) {
            $newId = $this->addTask($toTemplateId, [
                'parent_template_task_id'      => $newParentId,
                'is_group'                     => $child->isGroup(),
                'category_id'                  => $child->getCategoryId(),
                'title'                        => $child->getTitle(),
                'description'                  => $child->getDescription(),
                'task_type'                    => $child->getTaskType(),
                'slot_mode'                    => $child->getSlotMode(),
                'default_offset_minutes_start' => $child->getDefaultOffsetMinutesStart(),
                'default_offset_minutes_end'   => $child->getDefaultOffsetMinutesEnd(),
                'capacity_mode'                => $child->getCapacityMode(),
                'capacity_target'              => $child->getCapacityTarget(),
                'hours_default'                => $child->getHoursDefault(),
                'sort_order'                   => $child->getSortOrder(),
            ]);
            $count++;
            if ($child->isGroup() && $child->getId() !== null) {
                $count += $this->copyTaskSubtree(
                    $fromTemplateId,
                    $toTemplateId,
                    $child->getId(),
                    $newId
                );
            }
        }
        return $count;
    }

    // =========================================================================
    // Tree-Editor-Operationen (Modul 6 I7c)
    //
    // Die meisten Tree-Operationen (moveTask, convertTaskToGroup,
    // convertTaskToLeaf, getTaskDepth, isTaskDescendantOf,
    // countActiveTaskChildren) existieren bereits aus der I7a-Vorbereitung und
    // werden vom neuen TemplateTaskTreeService genutzt. Hier ergaenzt werden
    // nur die beiden Methoden, die I7a noch nicht hat:
    //
    //   - reorderTaskSiblings: Parent-Geschwister-Reorder mit Step 10,
    //     analog zu EventTaskRepository::reorderSiblings (die bestehende
    //     reorderTasks() oben setzt sort_order als index-0/1/2 ueber ALLE
    //     Tasks eines Templates und stammt aus dem flachen I4-Editor).
    //   - maxSubtreeDepth: fuer Move-Validation (Subtree darf nicht unter
    //     eine Gruppe umgehaengt werden, die MaxDepth sprengt).
    //
    // Templates haben kein Soft-Delete auf Task-Ebene (hartes DELETE via
    // deleteTask() oben) und keine Assignments — daher auch keine
    // countActiveAssignments-Analoge.
    // =========================================================================

    /**
     * Geschwister einer Ebene neu sortieren. sort_order in Schritten von 10
     * (analog zu EventTaskRepository::reorderSiblings — billige Einzelmoves
     * ohne Re-Numbering moeglich). Defense-in-Depth via template_id-Filter.
     */
    public function reorderTaskSiblings(int $templateId, array $orderedIds): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_template_tasks
             SET sort_order = :ord
             WHERE id = :id AND template_id = :tid"
        );
        $step = 10;
        foreach (array_values($orderedIds) as $index => $taskId) {
            $stmt->execute([
                'ord' => ($index + 1) * $step,
                'id'  => (int) $taskId,
                'tid' => $templateId,
            ]);
        }
    }

    /**
     * Maximal-Tiefe des Subtrees unter einem Knoten (0 = nur der Knoten
     * selbst, 1 = hat Kinder, ...). Wird beim Move validiert: ein Knoten
     * mit tiefem Subtree darf nicht unter eine Gruppe umgehaengt werden,
     * wenn dadurch die Maximaltiefe ueberschritten wuerde.
     */
    public function maxSubtreeDepth(int $taskId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM event_template_tasks
             WHERE parent_template_task_id = :pid"
        );
        $stmt->execute(['pid' => $taskId]);
        $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($children === false || count($children) === 0) {
            return 0;
        }
        $max = 0;
        foreach ($children as $childId) {
            $depth = 1 + $this->maxSubtreeDepth((int) $childId);
            if ($depth > $max) {
                $max = $depth;
            }
        }
        return $max;
    }
}
