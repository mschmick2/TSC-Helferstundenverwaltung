<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\TreeWalkLimits;
use App\Models\EventTask;
use App\Models\TaskStatus;

/**
 * Read-only Helfer fuer den hierarchischen Aufgabenbaum (Modul 6 I7a).
 *
 * Baut aus dem flachen Repository-Output (EventTaskRepository::findByEvent)
 * eine in-memory Struktur fuer das Rendering:
 *   - Tree-Struktur: Top-Level-Knoten mit verschachtelten 'children'-Arrays.
 *   - Pro Gruppe: aufsummierter Helferbedarf (capacity_target) und Stunden
 *     (hours_default) aller Leaves im Subtree.
 *   - Pro Leaf: Pfad zur Wurzel als Liste der Gruppen-Titel — fuer iCal-
 *     DESCRIPTION/CATEGORIES (Pfad-Praefix-Mapping aus dem G1-Plan) und
 *     UI-Breadcrumbs im Editor-Modal (Layout-Entscheidung B aus G1-Delta).
 *
 * Macht KEINE DB-Queries — alles erfolgt aus dem uebergebenen Task-Array.
 * Erwartete Komplexitaet: O(n) bei einmaligem Walk pro Event-Render.
 *
 * Aggregator-Knoten-Struktur:
 *   [
 *     'task'                => EventTask,
 *     'children'            => array<int, gleicher Knoten-Struct>,
 *     'helpers_subtree'     => int    (Summe capacity_target ueber Leaves)
 *     'hours_subtree'       => float  (Summe hours_default ueber Leaves)
 *     'leaves_subtree'      => int    (Anzahl Leaves im Subtree)
 *     'open_slots_subtree'  => null   (wird in I7b befuellt: helpers_subtree
 *                                       minus aktive Assignments. Bleibt in
 *                                       I7a bewusst null, damit der Renderer
 *                                       in I7b die Property nur fuellen muss
 *                                       — kein API-Bruch.)
 *     'status'              => TaskStatus|null   (I7b3: EMPTY/PARTIAL/FULL
 *                                       pro Leaf basierend auf
 *                                       capacity_target + aktive Zusagen;
 *                                       pro Gruppe als schlechtester Status
 *                                       aller Kinder — G1-Entscheidung A
 *                                       Variante 1. null, wenn
 *                                       assignmentCounts leer oder Gruppe
 *                                       ohne Kinder — Views rendern dann
 *                                       keine Farbe.)
 *   ]
 */
final class TaskTreeAggregator
{
    /**
     * Tree mit verschachtelten children und Aggregaten je Gruppe.
     *
     * @param EventTask[] $tasks Alle aktiven Tasks eines Events (flach).
     * @param array<int,int>|null $assignmentCounts Optional: Map task_id ->
     *     Anzahl aktiver Zusagen. Wenn uebergeben (auch als leeres Array),
     *     werden `open_slots_subtree` und `status` pro Knoten berechnet —
     *     fehlende Task-IDs gelten als 0 Zusagen. Wenn null (Default),
     *     bleiben beide Felder null (I7a-Verhalten, fuer Editor-Sicht
     *     ausreichend). Unterscheidung null-vs-[] ist wichtig: leer heisst
     *     "Event hat keine Zusagen, alle Leaves sind EMPTY"; null heisst
     *     "Aufrufer liefert keine Zusagen-Info".
     * @return array<int, array{
     *     task: EventTask,
     *     children: array,
     *     helpers_subtree: int,
     *     hours_subtree: float,
     *     leaves_subtree: int,
     *     open_slots_subtree: int|null,
     *     status: TaskStatus|null
     * }>
     */
    public function buildTree(array $tasks, ?array $assignmentCounts = null): array
    {
        // Index nach parent-ID (0 = Top-Level, weil int-Keys hashbar sind)
        $byParent = [];
        foreach ($tasks as $t) {
            $pid = $t->getParentTaskId() ?? 0;
            if (!isset($byParent[$pid])) {
                $byParent[$pid] = [];
            }
            $byParent[$pid][] = $t;
        }

        // sortiere jede Ebene stabil nach (sort_order, title)
        foreach ($byParent as &$siblings) {
            usort($siblings, function (EventTask $a, EventTask $b): int {
                $cmp = $a->getSortOrder() <=> $b->getSortOrder();
                return $cmp !== 0 ? $cmp : strcmp($a->getTitle(), $b->getTitle());
            });
        }
        unset($siblings);

        return $this->assemble($byParent, 0, $assignmentCounts);
    }

    /**
     * Status-Rollup fuer eine Gruppe: schlechtester Kinderstatus gewinnt
     * (G1-Entscheidung A Variante 1). null-Statuswerte (z.B. Kind ist eine
     * leere Gruppe) werden gefiltert, bevor worst() laeuft — sie tragen
     * keine Aussage und duerfen den Rollup nicht verfaelschen. Eine Gruppe
     * ohne auswertbare Kinder bekommt selbst null.
     *
     * @param array<int, array{status: TaskStatus|null}> $children
     */
    private function rollupGroupStatus(array $children): ?TaskStatus
    {
        $childStatuses = [];
        foreach ($children as $child) {
            if ($child['status'] !== null) {
                $childStatuses[] = $child['status'];
            }
        }
        return TaskStatus::worst($childStatuses);
    }

    /**
     * Pfad eines Knotens vom Top-Level bis zum unmittelbaren Eltern-Knoten
     * (ohne den Knoten selbst). Bei Top-Level-Knoten: leere Liste.
     *
     * @param EventTask[] $tasks Alle Tasks des Events (flach).
     * @return array<int, array{id:int, title:string, is_group:bool}>
     */
    public function getAncestorPath(int $taskId, array $tasks): array
    {
        $byId = [];
        foreach ($tasks as $t) {
            $byId[(int) $t->getId()] = $t;
        }

        if (!isset($byId[$taskId])) {
            return [];
        }

        $stack = [];
        $current = $byId[$taskId];
        $iterations = 0;
        while ($current->getParentTaskId() !== null && $iterations < TreeWalkLimits::SAFETY_DEPTH_CAP) {
            $iterations++;
            $parentId = $current->getParentTaskId();
            if (!isset($byId[$parentId])) {
                break;
            }
            $parent = $byId[$parentId];
            $stack[] = [
                'id'       => (int) $parent->getId(),
                'title'    => $parent->getTitle(),
                'is_group' => $parent->isGroup(),
            ];
            $current = $parent;
        }
        return array_reverse($stack);
    }

    /**
     * Pfad-String fuer iCal-DESCRIPTION oder Editor-Breadcrumbs:
     *   "Hallenaufbau > Musik > Musik aufbauen"
     *
     * @param EventTask[] $tasks
     */
    public function getPathString(int $taskId, array $tasks, string $separator = ' > '): string
    {
        $byId = [];
        foreach ($tasks as $t) {
            $byId[(int) $t->getId()] = $t;
        }
        if (!isset($byId[$taskId])) {
            return '';
        }
        $titles = array_map(static fn(array $n) => $n['title'], $this->getAncestorPath($taskId, $tasks));
        $titles[] = $byId[$taskId]->getTitle();
        return implode($separator, $titles);
    }

    /**
     * Liste der Gruppen-Titel auf dem Pfad zum Knoten (exklusive Knoten selbst),
     * fuer iCal-CATEGORIES. Wurzel zuerst.
     *
     * @param EventTask[] $tasks
     * @return string[]
     */
    public function getCategoryNames(int $taskId, array $tasks): array
    {
        $names = [];
        foreach ($this->getAncestorPath($taskId, $tasks) as $node) {
            if ($node['is_group']) {
                $names[] = $node['title'];
            }
        }
        return $names;
    }

    /**
     * Rekursiver Aufbau der Tree-Knoten samt Subtree-Aggregaten.
     *
     * @param array<int, EventTask[]> $byParent Geschwister-Map (Key 0 = Top-Level).
     * @param array<int,int>|null $assignmentCounts Map task_id -> aktive
     *     Zusagen. null deaktiviert die open_slots_subtree-/status-Berechnung
     *     (beide Felder bleiben null, I7a-Default). Ein Array — auch ein
     *     leeres — aktiviert sie; fehlende Task-IDs gelten als 0 Zusagen.
     * @return array<int, array{
     *     task: EventTask,
     *     children: array,
     *     helpers_subtree: int,
     *     hours_subtree: float,
     *     leaves_subtree: int,
     *     open_slots_subtree: int|null,
     *     status: TaskStatus|null
     * }>
     */
    private function assemble(array $byParent, int $parentKey, ?array $assignmentCounts = null): array
    {
        $countsActive = $assignmentCounts !== null;
        $out = [];
        foreach ($byParent[$parentKey] ?? [] as $task) {
            $children = $this->assemble($byParent, (int) $task->getId(), $assignmentCounts);
            $openSlots = 0;
            $status    = null;

            if ($task->isGroup()) {
                $helpers = 0;
                $hours = 0.0;
                $leaves = 0;
                foreach ($children as $child) {
                    $helpers += $child['helpers_subtree'];
                    $hours += $child['hours_subtree'];
                    $leaves += $child['leaves_subtree'];
                    if ($countsActive) {
                        $openSlots += $child['open_slots_subtree'] ?? 0;
                    }
                }
                if ($countsActive) {
                    $status = $this->rollupGroupStatus($children);
                }
            } else {
                // Leaf: capacity_target=NULL bedeutet "unbegrenzt" — fuer das
                // Rollup zaehlen wir 0 als bekannten Bedarf, damit "5 offen"
                // nicht durch eine unbegrenzte Aufgabe ins Astronomische steigt.
                $helpers = $task->getCapacityTarget() ?? 0;
                $hours = $task->getHoursDefault();
                $leaves = 1;
                if ($countsActive) {
                    $taken = $assignmentCounts[(int) $task->getId()] ?? 0;
                    $openSlots = max(0, $helpers - $taken);
                    // I7b3: Leaf-Status aus capacity_target + aktueller
                    // Zusage-Anzahl. Bei unbegrenzten Leaves (capacity_target
                    // null) wird die Sonderlogik in TaskStatus::forLeaf
                    // angewendet — FULL nie erreicht.
                    $status = TaskStatus::forLeaf(
                        $task->getCapacityTarget(),
                        $taken
                    );
                }
            }

            $out[] = [
                'task'               => $task,
                'children'           => $children,
                'helpers_subtree'    => $helpers,
                'hours_subtree'      => $hours,
                'leaves_subtree'     => $leaves,
                'open_slots_subtree' => $countsActive ? $openSlots : null,
                'status'             => $status,
            ];
        }
        return $out;
    }
}
