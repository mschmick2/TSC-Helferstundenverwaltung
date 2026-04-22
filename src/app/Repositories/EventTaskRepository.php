<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\TreeWalkLimits;
use App\Models\EventTask;
use PDO;

/**
 * Repository fuer Event-Aufgaben (und Beigaben).
 *
 * Seit Modul 6 I7a: zusaetzlich Adjacency-List-Tree (parent_task_id, is_group).
 * Tree-Operationen (Move, Reorder, Soft-Delete einzelner Knoten) gehen ueber
 * den TaskTreeService — das Repository liefert nur die Daten-Primitive.
 */
class EventTaskRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Alle aktiven Tasks eines Events (flach, sortiert nach sort_order).
     * Tree-Aufbau erfolgt im Service.
     *
     * @return EventTask[]
     */
    public function findByEvent(int $eventId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM event_tasks
             WHERE event_id = :event_id AND deleted_at IS NULL
             ORDER BY sort_order ASC, title ASC"
        );
        $stmt->execute(['event_id' => $eventId]);

        $tasks = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tasks[] = EventTask::fromArray($row);
        }
        return $tasks;
    }

    public function findById(int $id): ?EventTask
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM event_tasks WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? EventTask::fromArray($row) : null;
    }

    public function getRawById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM event_tasks WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Aktive Geschwister eines Knotens (gleicher parent_task_id, gleiches Event).
     * Top-Level-Knoten haben parentId=null.
     *
     * @return EventTask[]
     */
    public function findChildren(int $eventId, ?int $parentId): array
    {
        if ($parentId === null) {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM event_tasks
                 WHERE event_id = :event_id
                   AND parent_task_id IS NULL
                   AND deleted_at IS NULL
                 ORDER BY sort_order ASC, id ASC"
            );
            $stmt->execute(['event_id' => $eventId]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM event_tasks
                 WHERE event_id = :event_id
                   AND parent_task_id = :pid
                   AND deleted_at IS NULL
                 ORDER BY sort_order ASC, id ASC"
            );
            $stmt->execute(['event_id' => $eventId, 'pid' => $parentId]);
        }

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = EventTask::fromArray($row);
        }
        return $rows;
    }

    /**
     * Anzahl aktiver Kinder unter einem Knoten (one-level, fuer Loesch-Check).
     */
    public function countActiveChildren(int $taskId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM event_tasks
             WHERE parent_task_id = :pid AND deleted_at IS NULL"
        );
        $stmt->execute(['pid' => $taskId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Tiefe eines Knotens, ausgehend vom Top-Level (Top-Level = 0).
     * Walkt die parent-Kette hoch. Cap bei TreeWalkLimits::SAFETY_DEPTH_CAP
     * als Sicherung gegen versehentliche Zyklen (Service-Validation deckelt
     * regulaer auf tree_max_depth).
     */
    public function getDepth(int $taskId): int
    {
        $depth = 0;
        $current = $taskId;
        for ($i = 0; $i < TreeWalkLimits::SAFETY_DEPTH_CAP; $i++) {
            $stmt = $this->pdo->prepare(
                "SELECT parent_task_id FROM event_tasks WHERE id = :id"
            );
            $stmt->execute(['id' => $current]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || $row['parent_task_id'] === null) {
                return $depth;
            }
            $current = (int) $row['parent_task_id'];
            $depth++;
        }
        // Notbremse: Tiefe ueber SAFETY_DEPTH_CAP deutet auf einen Datenbankzyklus hin
        return $depth;
    }

    /**
     * Pfad vom Top-Level zur Wurzel des gegebenen Knotens (exklusive Knoten selbst),
     * als Liste von [id, title, is_group]-Eintraegen. Genutzt vom TaskTreeAggregator
     * fuer iCal-DESCRIPTION/CATEGORIES und Breadcrumbs in der UI.
     *
     * @return array<int, array{id:int,title:string,is_group:bool}>
     */
    public function getAncestorPath(int $taskId): array
    {
        $stack = [];
        $current = $taskId;
        for ($i = 0; $i < TreeWalkLimits::SAFETY_DEPTH_CAP; $i++) {
            $stmt = $this->pdo->prepare(
                "SELECT id, title, is_group, parent_task_id FROM event_tasks WHERE id = :id"
            );
            $stmt->execute(['id' => $current]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || $row['parent_task_id'] === null) {
                return array_reverse($stack);
            }
            $parentId = (int) $row['parent_task_id'];
            $pStmt = $this->pdo->prepare(
                "SELECT id, title, is_group FROM event_tasks WHERE id = :id"
            );
            $pStmt->execute(['id' => $parentId]);
            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($pRow === false) {
                return array_reverse($stack);
            }
            $stack[] = [
                'id'       => (int) $pRow['id'],
                'title'    => (string) $pRow['title'],
                'is_group' => (int) $pRow['is_group'] === 1,
            ];
            $current = $parentId;
        }
        return array_reverse($stack);
    }

    /**
     * Maximale Tiefe des Subtrees unter $taskId, relativ zum Knoten selbst
     * (Knoten ohne Kinder => 0, ein direktes Kind => 1, ein Enkelkind => 2 ...).
     * Rekursive BFS. Cap bei SAFETY_DEPTH_CAP als Notbremse gegen Datenzyklen,
     * aeussere Iterationsgrenze BFS_ITERATIONS_CAP. Beide Grenzen sind in
     * App\Helpers\TreeWalkLimits dokumentiert.
     *
     * Hinweis (E3): Das Verhalten gegen einen kuenstlich eingespielten
     * Datenbankzyklus ist mit der aktuellen Statisch-Test-Suite nicht
     * abdeckbar (kein Bootstrap einer echten Test-DB). Das Follow-up-Ticket
     * "FeatureTestCase-Setup fuer TaskTreeService und EventTemplateService"
     * deckt das ab.
     */
    public function maxSubtreeDepth(int $taskId): int
    {
        $maxDepth = 0;
        $current = [['id' => $taskId, 'depth' => 0]];
        $iterations = 0;
        while ($current !== [] && $iterations < TreeWalkLimits::BFS_ITERATIONS_CAP) {
            $iterations++;
            $next = [];
            foreach ($current as $node) {
                $stmt = $this->pdo->prepare(
                    "SELECT id FROM event_tasks
                     WHERE parent_task_id = :pid AND deleted_at IS NULL"
                );
                $stmt->execute(['pid' => $node['id']]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $childDepth = $node['depth'] + 1;
                    if ($childDepth > $maxDepth) {
                        $maxDepth = $childDepth;
                    }
                    if ($childDepth >= TreeWalkLimits::SAFETY_DEPTH_CAP) {
                        return $childDepth;
                    }
                    $next[] = ['id' => (int) $row['id'], 'depth' => $childDepth];
                }
            }
            $current = $next;
        }
        return $maxDepth;
    }

    /**
     * Pruefen, ob $candidateAncestor in der parent-Kette von $taskId liegt.
     * Wird vom Service genutzt, um Zyklen beim Move zu verhindern.
     */
    public function isDescendantOf(int $taskId, int $candidateAncestor): bool
    {
        $current = $taskId;
        for ($i = 0; $i < TreeWalkLimits::SAFETY_DEPTH_CAP; $i++) {
            $stmt = $this->pdo->prepare(
                "SELECT parent_task_id FROM event_tasks WHERE id = :id"
            );
            $stmt->execute(['id' => $current]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || $row['parent_task_id'] === null) {
                return false;
            }
            $parent = (int) $row['parent_task_id'];
            if ($parent === $candidateAncestor) {
                return true;
            }
            $current = $parent;
        }
        return false;
    }

    /**
     * Neue Aufgabe anlegen. Akzeptiert seit I7a parent_task_id und is_group.
     *
     * Wichtig: slot_mode wird NUR mit Default 'fix' belegt, wenn der Schluessel
     * fehlt. Wenn explizit null uebergeben (Gruppe), bleibt null erhalten —
     * sonst verletzt der Insert chk_et_group_shape.
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO event_tasks
             (event_id, parent_task_id, is_group, category_id, title, description,
              task_type, slot_mode, start_at, end_at,
              capacity_mode, capacity_target, hours_default, sort_order)
             VALUES
             (:event_id, :parent_task_id, :is_group, :category_id, :title, :description,
              :task_type, :slot_mode, :start_at, :end_at,
              :capacity_mode, :capacity_target, :hours_default, :sort_order)"
        );
        $stmt->execute([
            'event_id'        => $data['event_id'],
            'parent_task_id'  => $data['parent_task_id'] ?? null,
            'is_group'        => isset($data['is_group']) && $data['is_group'] ? 1 : 0,
            'category_id'     => $data['category_id'] ?? null,
            'title'           => $data['title'],
            'description'     => $data['description'] ?? null,
            'task_type'       => $data['task_type'] ?? EventTask::TYPE_AUFGABE,
            'slot_mode'       => array_key_exists('slot_mode', $data)
                ? $data['slot_mode']
                : EventTask::SLOT_FIX,
            'start_at'        => $data['start_at'] ?? null,
            'end_at'          => $data['end_at'] ?? null,
            'capacity_mode'   => $data['capacity_mode'] ?? EventTask::CAP_UNBEGRENZT,
            'capacity_target' => $data['capacity_target'] ?? null,
            'hours_default'   => $data['hours_default'] ?? 0.0,
            'sort_order'      => (int) ($data['sort_order'] ?? 0),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Task aktualisieren. version-Spalte wird immer inkrementiert, damit Aussen-
     * stehende (Event-Admin-UI) eine verlaessliche Versionsnummer erhalten.
     * Eine Version-Pruefung (Optimistic Lock) gibt es derzeit nicht, weil Tasks
     * nur ueber die Event-Detail-Seite editiert werden und dort der Event-Schutz
     * schon greift. Parameter $expectedVersion ist fuer spaetere Aktivierung reserviert.
     */
    public function update(int $id, array $data, ?int $expectedVersion = null): bool
    {
        $sql = "UPDATE event_tasks SET
                    category_id = :category_id,
                    title = :title,
                    description = :description,
                    task_type = :task_type,
                    slot_mode = :slot_mode,
                    start_at = :start_at,
                    end_at = :end_at,
                    capacity_mode = :capacity_mode,
                    capacity_target = :capacity_target,
                    hours_default = :hours_default,
                    sort_order = :sort_order,
                    version = version + 1
                WHERE id = :id AND deleted_at IS NULL";
        $params = [
            'category_id' => $data['category_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'task_type' => $data['task_type'] ?? EventTask::TYPE_AUFGABE,
            'slot_mode' => array_key_exists('slot_mode', $data)
                ? $data['slot_mode']
                : EventTask::SLOT_FIX,
            'start_at' => $data['start_at'] ?? null,
            'end_at' => $data['end_at'] ?? null,
            'capacity_mode' => $data['capacity_mode'] ?? EventTask::CAP_UNBEGRENZT,
            'capacity_target' => $data['capacity_target'] ?? null,
            'hours_default' => $data['hours_default'] ?? 0.0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'id' => $id,
        ];
        if ($expectedVersion !== null) {
            $sql .= " AND version = :version";
            $params['version'] = $expectedVersion;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /**
     * Knoten umhaengen (neuer parent + neuer sort_order in einem Atomar-Update).
     * Validierung (Tiefe, Zyklus, Group-Shape) liegt im Service.
     */
    public function move(int $taskId, ?int $newParentId, int $newSortOrder): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_tasks
             SET parent_task_id = :pid,
                 sort_order = :ord,
                 version = version + 1
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute([
            'pid' => $newParentId,
            'ord' => $newSortOrder,
            'id'  => $taskId,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Geschwister einer Ebene neu sortieren. orderedIds enthalten die Task-IDs
     * in gewuenschter Reihenfolge; sort_order wird in Schritten von 10 gesetzt
     * (Default-Strategie aus dem Plan, billige Einzelmoves ohne Re-Numbering).
     *
     * Es wird nur sort_order angefasst, parent bleibt unveraendert.
     */
    public function reorderSiblings(array $orderedIds): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_tasks
             SET sort_order = :ord, version = version + 1
             WHERE id = :id AND deleted_at IS NULL"
        );
        $step = 10;
        foreach (array_values($orderedIds) as $index => $taskId) {
            $stmt->execute([
                'ord' => ($index + 1) * $step,
                'id'  => (int) $taskId,
            ]);
        }
    }

    /**
     * Knoten in eine Gruppe konvertieren (oder zurueck zu Leaf). Setzt die
     * Shape-Felder atomar, damit die Group-Shape-Constraint nicht zwischen-
     * zeitlich verletzt wird.
     */
    public function convertToGroup(int $taskId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_tasks
             SET is_group = 1,
                 slot_mode = NULL,
                 start_at = NULL,
                 end_at = NULL,
                 capacity_mode = 'unbegrenzt',
                 capacity_target = NULL,
                 hours_default = 0,
                 task_type = 'aufgabe',
                 version = version + 1
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $taskId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Gruppe zurueck in Leaf konvertieren. Erwartet Shape-Defaults vom Service
     * (slot_mode, capacity_mode, hours_default usw.), damit die Konvertierung
     * konsistent zu den Validierungen ist.
     */
    public function convertToLeaf(int $taskId, array $leafData): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_tasks
             SET is_group = 0,
                 task_type = :task_type,
                 slot_mode = :slot_mode,
                 start_at = :start_at,
                 end_at = :end_at,
                 capacity_mode = :capacity_mode,
                 capacity_target = :capacity_target,
                 hours_default = :hours_default,
                 version = version + 1
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute([
            'task_type'       => $leafData['task_type'] ?? EventTask::TYPE_AUFGABE,
            'slot_mode'       => $leafData['slot_mode'] ?? EventTask::SLOT_FIX,
            'start_at'        => $leafData['start_at'] ?? null,
            'end_at'          => $leafData['end_at'] ?? null,
            'capacity_mode'   => $leafData['capacity_mode'] ?? EventTask::CAP_UNBEGRENZT,
            'capacity_target' => $leafData['capacity_target'] ?? null,
            'hours_default'   => $leafData['hours_default'] ?? 0.0,
            'id'              => $taskId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function softDelete(int $id, int $deletedBy): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE event_tasks SET deleted_at = NOW(), deleted_by = :user, version = version + 1
             WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['user' => $deletedBy, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Aktive Zusagen (vorgeschlagen + bestaetigt + storno_angefragt) fuer
     * Capacity-Check auf Task-Ebene. Storniert/abgeschlossen zaehlen nicht -
     * der Slot ist wieder frei.
     */
    public function countActiveAssignments(int $taskId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM event_task_assignments
             WHERE task_id = :task_id
               AND status IN (:s_vorg, :s_best, :s_storno)
               AND deleted_at IS NULL"
        );
        $stmt->execute([
            'task_id'  => $taskId,
            's_vorg'   => \App\Models\EventTaskAssignment::STATUS_VORGESCHLAGEN,
            's_best'   => \App\Models\EventTaskAssignment::STATUS_BESTAETIGT,
            's_storno' => \App\Models\EventTaskAssignment::STATUS_STORNO_ANGEFRAGT,
        ]);
        return (int) $stmt->fetchColumn();
    }
}
