<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\TreeWalkLimits;
use App\Models\EventTemplateTask;

/**
 * Read-only Helfer fuer den hierarchischen Aufgabenbaum eines Event-Templates
 * (Modul 6 I7c — Parallel-Implementation zu TaskTreeAggregator).
 *
 * Strukturell analog zum Event-Aggregator:
 *   - buildTree(): flache Task-Liste -> verschachtelte {task, children, ...}-
 *     Knoten. Geschwister werden pro Ebene stabil nach (sort_order, title)
 *     sortiert.
 *   - getAncestorPath(), getPathString() fuer Editor-Breadcrumbs im Modal.
 *
 * Unterschiede zu TaskTreeAggregator:
 *   - Keine assignmentCounts, kein open_slots_subtree, kein status-Rollup —
 *     Templates haben keine Zusagen (fachlich) und keine Farbkodierung.
 *   - Zeit-Modell: defaultOffsetMinutesStart/End (Integer Minuten) statt
 *     start_at/end_at (DATETIME-String). Der Aggregator selbst beruehrt
 *     die Zeiten nicht; das Rendering im Partial formatiert sie als
 *     "+30 min" / "+2 h 0 min".
 *
 * Macht keine DB-Queries — reine In-Memory-Transformation.
 */
final class TemplateTaskTreeAggregator
{
    /**
     * Tree mit verschachtelten children und Aggregaten je Gruppe.
     *
     * @param EventTemplateTask[] $tasks Alle Tasks eines Templates (flach).
     * @return array<int, array{
     *     task: EventTemplateTask,
     *     children: array,
     *     helpers_subtree: int,
     *     hours_subtree: float,
     *     leaves_subtree: int
     * }>
     */
    public function buildTree(array $tasks): array
    {
        $byParent = [];
        foreach ($tasks as $t) {
            $pid = $t->getParentTemplateTaskId() ?? 0;
            if (!isset($byParent[$pid])) {
                $byParent[$pid] = [];
            }
            $byParent[$pid][] = $t;
        }

        foreach ($byParent as &$siblings) {
            usort($siblings, function (EventTemplateTask $a, EventTemplateTask $b): int {
                $cmp = $a->getSortOrder() <=> $b->getSortOrder();
                return $cmp !== 0 ? $cmp : strcmp($a->getTitle(), $b->getTitle());
            });
        }
        unset($siblings);

        return $this->assemble($byParent, 0);
    }

    /**
     * Pfad eines Knotens vom Top-Level bis zum unmittelbaren Eltern-Knoten
     * (ohne den Knoten selbst). Bei Top-Level-Knoten: leere Liste.
     *
     * @param EventTemplateTask[] $tasks
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
        while ($current->getParentTemplateTaskId() !== null
            && $iterations < TreeWalkLimits::SAFETY_DEPTH_CAP
        ) {
            $iterations++;
            $parentId = $current->getParentTemplateTaskId();
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
     * Pfad-String fuer Breadcrumbs im Editor-Modal.
     *
     * @param EventTemplateTask[] $tasks
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
        $titles = array_map(
            static fn(array $n) => $n['title'],
            $this->getAncestorPath($taskId, $tasks)
        );
        $titles[] = $byId[$taskId]->getTitle();
        return implode($separator, $titles);
    }

    /**
     * @param array<int, EventTemplateTask[]> $byParent
     */
    private function assemble(array $byParent, int $parentKey): array
    {
        $out = [];
        foreach ($byParent[$parentKey] ?? [] as $task) {
            $children = $this->assemble($byParent, (int) $task->getId());

            if ($task->isGroup()) {
                $helpers = 0;
                $hours   = 0.0;
                $leaves  = 0;
                foreach ($children as $child) {
                    $helpers += $child['helpers_subtree'];
                    $hours   += $child['hours_subtree'];
                    $leaves  += $child['leaves_subtree'];
                }
            } else {
                // Leaf: capacity_target NULL = unbegrenzt -> 0 bekannt.
                $helpers = $task->getCapacityTarget() ?? 0;
                $hours   = $task->getHoursDefault();
                $leaves  = 1;
            }

            $out[] = [
                'task'            => $task,
                'children'        => $children,
                'helpers_subtree' => $helpers,
                'hours_subtree'   => $hours,
                'leaves_subtree'  => $leaves,
            ];
        }
        return $out;
    }
}
