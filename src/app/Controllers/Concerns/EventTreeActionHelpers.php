<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use App\Models\EventTask;
use App\Models\TaskStatus;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Event-spezifische Helper fuer Tree-Aktions-Controller.
 *
 * Extrahiert aus OrganizerEventEditController und EventAdminController
 * in I7e-B.0.1. Wird NICHT vom Template-Controller genutzt — Templates
 * haben keine Belegungs-Summary (keine Capacity-/Zusage-Daten) und
 * ihre Form-Feldnamen weichen ab (parent_template_task_id,
 * default_offset_minutes_*), weshalb die Normalisierungs- und
 * Serialisierungs-Logik eigenstaendig bleibt.
 *
 * Enthaltene Methoden:
 *   - normalizeTreeFormInputs — "" → null fuer Shape-Felder, parent_task_id
 *     ?int-Cast.
 *   - serializeTreeForJson    — Aggregator-Output fuer showTaskTree-JSON
 *     flachklopfen (inkl. Status-Enum → String).
 *   - sortFlatListByStart     — chronologische Sidebar-Liste, Nulls ans
 *     Ende. PHP 8+ usort-Stabilitaet erhaelt DFS-Sekundaer-Sort.
 *   - computeBelegungsSummary — Belegungs-Zahlen fuer die Sidebar-Panel-2.
 *   - walkTreeForSummary      — Gruppen-Count (Helfer fuer
 *     computeBelegungsSummary).
 *   - assertTaskBelongsToEvent — IDOR-Scope-Check (ersetzt den
 *     duplizierten Inline-Block aus dem G4-Fix, Commit 2a16823).
 *
 * Konventions-Voraussetzungen:
 *   - `$this->taskRepo` (EventTaskRepository) fuer assertTaskBelongsToEvent.
 */
trait EventTreeActionHelpers
{
    /**
     * HTTP-Form-Inputs in Service-taugliche Typen ueberfuehren. Zwei
     * konkrete Probleme werden hier geloest:
     *
     *  1) parent_task_id kommt aus HTML-Hidden-Fields immer als String.
     *     Der Service hat eine strikte ?int-Signatur (normalizeParentId)
     *     und wirft unter declare(strict_types=1) einen TypeError bei
     *     String-Input. "" / "0" / null / 0 → null (= Top-Level-Knoten),
     *     alles andere (int).
     *
     *  2) Datetime-Inputs (start_at, end_at) und optionale Integer-Felder
     *     (category_id, capacity_target) liefern "" wenn leer gelassen.
     *     Der Service-Null-Check (slot_mode=fix ⇒ start/end NOT NULL)
     *     unterscheidet "" und null nicht — ohne Normalisierung landet
     *     "" als ungueltiger DATETIME-String im INSERT und wirft eine
     *     PDOException statt einer lesbaren ValidationException. ""
     *     → null, damit die Service-Validation korrekt greift und der
     *     User die Message im Toast sieht.
     */
    protected function normalizeTreeFormInputs(array $data): array
    {
        if (array_key_exists('parent_task_id', $data)) {
            $pid = $data['parent_task_id'];
            $data['parent_task_id'] = ($pid === null || $pid === '' || $pid === '0' || $pid === 0)
                ? null
                : (int) $pid;
        }
        foreach (['start_at', 'end_at', 'category_id', 'capacity_target'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }
        return $data;
    }

    /**
     * Aggregator-Output fuer showTaskTree-JSON flachklopfen — sonst kommt
     * EventTask-Objekt als leeres JSON zurueck.
     *
     * @param array<int, array{task:EventTask, children:array, helpers_subtree:int, hours_subtree:float, leaves_subtree:int, open_slots_subtree:int|null, status:?TaskStatus}> $tree
     * @return array<int, array<string, mixed>>
     */
    protected function serializeTreeForJson(array $tree): array
    {
        $out = [];
        foreach ($tree as $node) {
            $task = $node['task'];
            $out[] = [
                'id'                 => (int) $task->getId(),
                'event_id'           => $task->getEventId(),
                'parent_task_id'     => $task->getParentTaskId(),
                'is_group'           => $task->isGroup() ? 1 : 0,
                'category_id'        => $task->getCategoryId(),
                'title'              => $task->getTitle(),
                'description'        => $task->getDescription(),
                'task_type'          => $task->getTaskType(),
                'slot_mode'          => $task->getSlotMode(),
                'start_at'           => $task->getStartAt(),
                'end_at'             => $task->getEndAt(),
                'capacity_mode'      => $task->getCapacityMode(),
                'capacity_target'    => $task->getCapacityTarget(),
                'hours_default'      => $task->getHoursDefault(),
                'sort_order'         => $task->getSortOrder(),
                'helpers_subtree'    => $node['helpers_subtree'],
                'hours_subtree'      => $node['hours_subtree'],
                'leaves_subtree'     => $node['leaves_subtree'],
                'open_slots_subtree' => $node['open_slots_subtree'],
                'status'             => $node['status']?->value,
                'children'           => $this->serializeTreeForJson($node['children']),
            ];
        }
        return $out;
    }

    /**
     * Sortiert die flache Aufgabenliste nach Startzeit. Tasks ohne
     * Startzeit wandern ans Ende. PHP 8+ garantiert Stabilitaet von
     * usort, daher bleibt die Depth-First-Reihenfolge aus dem Aggregator
     * als Sekundaer-Sort erhalten.
     *
     * @param list<array{task:EventTask, status:?TaskStatus, helpers:int, open_slots:?int, ancestor_path:list<string>}> $flatList
     */
    protected function sortFlatListByStart(array &$flatList): void
    {
        usort($flatList, static function (array $a, array $b): int {
            /** @var EventTask $ta */
            $ta = $a['task'];
            /** @var EventTask $tb */
            $tb = $b['task'];
            $sa = $ta->getStartAt();
            $sb = $tb->getStartAt();
            if ($sa === null || $sa === '') {
                return $sb === null || $sb === '' ? 0 : 1;
            }
            if ($sb === null || $sb === '') {
                return -1;
            }
            return strcmp($sa, $sb);
        });
    }

    /**
     * Aggregiert Belegungs-Zahlen fuer die Editor-Sidebar.
     *
     * Achtung zur Semantik (I7e-A Phase 2c, Smoke-Bug-Fix):
     *   - `helpers_total` = Summe der capacity_target-Werte (Helfer-Soll).
     *   - `zusagen_aktiv` = tatsaechliche aktive Zusagen aus
     *     `$assignmentCounts` (countActiveByEvent).
     *
     * @param array $tree     Root-Nodes aus TaskTreeAggregator::buildTree
     * @param list<array{task:EventTask, status:?TaskStatus, helpers:int, open_slots:?int, ancestor_path:list<string>}> $flatList
     * @param array<int,int> $assignmentCounts  task_id -> Anzahl aktive Zusagen
     * @return array{
     *     leaf_count:int,
     *     group_count:int,
     *     helpers_total:int,
     *     zusagen_aktiv:int,
     *     open_slots:int,
     *     open_slots_known:bool,
     *     hours_default_total:float,
     *     status_counts:array{empty:int, partial:int, full:int}
     * }
     */
    protected function computeBelegungsSummary(array $tree, array $flatList, array $assignmentCounts = []): array
    {
        $helpersTotal   = 0;
        $openSlotsTotal = 0;
        $openSlotsKnown = true;
        $hoursTotal     = 0.0;
        $statusCounts   = ['empty' => 0, 'partial' => 0, 'full' => 0];

        foreach ($flatList as $entry) {
            $helpersTotal += (int) $entry['helpers'];
            $hoursTotal   += (float) $entry['task']->getHoursDefault();

            if ($entry['open_slots'] === null) {
                $openSlotsKnown = false;
            } else {
                $openSlotsTotal += (int) $entry['open_slots'];
            }

            if ($entry['status'] instanceof TaskStatus) {
                $statusCounts[$entry['status']->value]++;
            }
        }

        $zusagenAktiv = array_sum(array_map('intval', $assignmentCounts));

        $groupCount = 0;
        $this->walkTreeForSummary($tree, $groupCount);

        return [
            'leaf_count'          => count($flatList),
            'group_count'         => $groupCount,
            'helpers_total'       => $helpersTotal,
            'zusagen_aktiv'       => $zusagenAktiv,
            'open_slots'          => $openSlotsTotal,
            'open_slots_known'    => $openSlotsKnown,
            'hours_default_total' => $hoursTotal,
            'status_counts'       => $statusCounts,
        ];
    }

    /**
     * Rekursiver Walker fuer Gruppen-Count (Helfer fuer
     * computeBelegungsSummary).
     *
     * @param array $nodes
     */
    protected function walkTreeForSummary(array $nodes, int &$groupCount): void
    {
        foreach ($nodes as $node) {
            /** @var EventTask $task */
            $task = $node['task'];
            if ($task->isGroup()) {
                $groupCount++;
                $this->walkTreeForSummary((array) ($node['children'] ?? []), $groupCount);
            }
        }
    }

    /**
     * IDOR-Scope-Check (G4 Dim 3, extrahiert aus Commit 2a16823).
     *
     * Ruft taskRepo->findById, prueft Event-Zuordnung. Bei Miss oder
     * Task-aus-fremdem-Event: 404-Response zurueckgeben (Caller forwardet
     * sie direkt). Bei Treffer: null, Caller laeuft weiter.
     *
     * 404 bewusst statt 403 — verdeckt Task-Existenz in einem fremden
     * Event (Information-Leak-Schutz).
     */
    protected function assertTaskBelongsToEvent(
        int $taskId,
        int $eventId,
        Response $response
    ): ?Response {
        $task = $this->taskRepo->findById($taskId);
        if ($task === null || $task->getEventId() !== $eventId) {
            return $response->withStatus(404);
        }
        return null;
    }
}
