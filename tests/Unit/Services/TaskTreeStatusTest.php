<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\EventTask;
use App\Models\TaskStatus;
use App\Services\TaskTreeAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer TaskStatus-Enum + Aggregator-Integration (Modul 6 I7b3).
 *
 * Zwei Blocks:
 *   - Enum-Logik (forLeaf, severity, worst, cssClass, badgeLabel, ariaLabel)
 *     mit PHP-nativen Assertions.
 *   - Aggregator-Integration: buildTree mit gezielten Task-Fixtures +
 *     assignmentCounts-Map, prueft die status-Felder pro Leaf und per
 *     schlechtester-Kinderstatus-Rollup pro Gruppe.
 */
final class TaskTreeStatusTest extends TestCase
{
    // =========================================================================
    // TaskStatus::forLeaf
    // =========================================================================

    public function test_forLeaf_unlimited_no_assignments_returns_empty(): void
    {
        self::assertSame(TaskStatus::EMPTY, TaskStatus::forLeaf(null, 0));
    }

    public function test_forLeaf_unlimited_with_assignments_returns_partial(): void
    {
        self::assertSame(TaskStatus::PARTIAL, TaskStatus::forLeaf(null, 1));
        self::assertSame(TaskStatus::PARTIAL, TaskStatus::forLeaf(null, 42));
    }

    public function test_forLeaf_limited_no_assignments_returns_empty(): void
    {
        self::assertSame(TaskStatus::EMPTY, TaskStatus::forLeaf(5, 0));
    }

    public function test_forLeaf_limited_partially_filled_returns_partial(): void
    {
        self::assertSame(TaskStatus::PARTIAL, TaskStatus::forLeaf(5, 1));
        self::assertSame(TaskStatus::PARTIAL, TaskStatus::forLeaf(5, 4));
    }

    public function test_forLeaf_limited_fully_filled_returns_full(): void
    {
        self::assertSame(TaskStatus::FULL, TaskStatus::forLeaf(5, 5));
    }

    public function test_forLeaf_limited_overfilled_returns_full(): void
    {
        // Defensive: auch bei mehr Zusagen als target bleibt FULL, wirft nicht.
        self::assertSame(TaskStatus::FULL, TaskStatus::forLeaf(5, 7));
    }

    public function test_forLeaf_zero_capacity_target_behaves_like_unlimited(): void
    {
        // capacity_target=0 ist defensive Edge-Case (kein regulaerer DB-Wert),
        // wird wie unbegrenzt behandelt: FULL niemals, PARTIAL ab erster Zusage.
        self::assertSame(TaskStatus::EMPTY, TaskStatus::forLeaf(0, 0));
        self::assertSame(TaskStatus::PARTIAL, TaskStatus::forLeaf(0, 1));
    }

    // =========================================================================
    // TaskStatus::severity
    // =========================================================================

    public function test_severity_ordering_empty_lower_than_partial_lower_than_full(): void
    {
        self::assertLessThan(TaskStatus::PARTIAL->severity(), TaskStatus::EMPTY->severity());
        self::assertLessThan(TaskStatus::FULL->severity(), TaskStatus::PARTIAL->severity());
        self::assertSame(0, TaskStatus::EMPTY->severity());
        self::assertSame(1, TaskStatus::PARTIAL->severity());
        self::assertSame(2, TaskStatus::FULL->severity());
    }

    // =========================================================================
    // TaskStatus::worst (Gruppen-Rollup)
    // =========================================================================

    public function test_worst_returns_empty_when_any_child_empty(): void
    {
        self::assertSame(
            TaskStatus::EMPTY,
            TaskStatus::worst([
                TaskStatus::FULL,
                TaskStatus::FULL,
                TaskStatus::EMPTY,   // ein einziges EMPTY macht den Rollup EMPTY
                TaskStatus::PARTIAL,
            ])
        );
    }

    public function test_worst_returns_partial_when_only_partial_and_full(): void
    {
        self::assertSame(
            TaskStatus::PARTIAL,
            TaskStatus::worst([
                TaskStatus::FULL,
                TaskStatus::PARTIAL,
                TaskStatus::FULL,
            ])
        );
    }

    public function test_worst_returns_full_when_all_children_full(): void
    {
        self::assertSame(
            TaskStatus::FULL,
            TaskStatus::worst([
                TaskStatus::FULL,
                TaskStatus::FULL,
                TaskStatus::FULL,
            ])
        );
    }

    public function test_worst_with_empty_array_returns_null(): void
    {
        // Defensive: Gruppe ohne auswertbare Kinder hat keine Aussage.
        self::assertNull(TaskStatus::worst([]));
    }

    // =========================================================================
    // cssClass / badgeLabel / ariaLabel
    // =========================================================================

    public function test_cssClass_returns_expected_strings_for_each_status(): void
    {
        self::assertSame('task-status-empty',   TaskStatus::EMPTY->cssClass());
        self::assertSame('task-status-partial', TaskStatus::PARTIAL->cssClass());
        self::assertSame('task-status-full',    TaskStatus::FULL->cssClass());
    }

    public function test_badgeLabel_matches_g1_decision(): void
    {
        // G1-Entscheidung D: kurze Badge-Formulierung.
        self::assertSame('keine Zusage', TaskStatus::EMPTY->badgeLabel());
        self::assertSame('teilweise',    TaskStatus::PARTIAL->badgeLabel());
        self::assertSame('voll',         TaskStatus::FULL->badgeLabel());
    }

    public function test_ariaLabel_matches_g1_decision(): void
    {
        // G1-Entscheidung D: ausfuehrliches ARIA-Label fuer Screen-Reader.
        self::assertSame('Status: keine Zusagen',       TaskStatus::EMPTY->ariaLabel());
        self::assertSame('Status: teilweise besetzt',   TaskStatus::PARTIAL->ariaLabel());
        self::assertSame(
            'Status: vollstaendig besetzt',
            TaskStatus::FULL->ariaLabel()
        );
    }

    // =========================================================================
    // Aggregator-Integration
    // =========================================================================

    private function makeLeaf(int $id, int $eventId, ?int $parentId, ?int $capacityTarget, float $hours = 1.0): EventTask
    {
        return EventTask::fromArray([
            'id'              => $id,
            'event_id'        => $eventId,
            'parent_task_id'  => $parentId,
            'is_group'        => 0,
            'title'           => 'Leaf-' . $id,
            'task_type'       => EventTask::TYPE_AUFGABE,
            'slot_mode'       => EventTask::SLOT_VARIABEL,
            'capacity_mode'   => $capacityTarget === null ? EventTask::CAP_UNBEGRENZT : EventTask::CAP_ZIEL,
            'capacity_target' => $capacityTarget,
            'hours_default'   => $hours,
            'sort_order'      => $id,
        ]);
    }

    private function makeGroup(int $id, int $eventId, ?int $parentId): EventTask
    {
        return EventTask::fromArray([
            'id'              => $id,
            'event_id'        => $eventId,
            'parent_task_id'  => $parentId,
            'is_group'        => 1,
            'title'           => 'Group-' . $id,
            'task_type'       => EventTask::TYPE_AUFGABE,
            'slot_mode'       => null,
            'capacity_mode'   => EventTask::CAP_UNBEGRENZT,
            'capacity_target' => null,
            'hours_default'   => 0.0,
            'sort_order'      => $id,
        ]);
    }

    public function test_buildTree_without_assignmentCounts_sets_status_null(): void
    {
        $aggregator = new TaskTreeAggregator();
        $tasks = [$this->makeLeaf(1, 100, null, 5)];

        // kein assignmentCounts-Array → kein Status
        $tree = $aggregator->buildTree($tasks);

        self::assertCount(1, $tree);
        self::assertNull(
            $tree[0]['status'],
            'Ohne assignmentCounts soll der Aggregator keinen Status setzen; '
            . 'die Views rendern dann keine Farbkodierung.'
        );
    }

    public function test_buildTree_with_assignmentCounts_sets_status_per_leaf(): void
    {
        $aggregator = new TaskTreeAggregator();
        $tasks = [
            $this->makeLeaf(1, 100, null, 5),   // leer: 0/5 → EMPTY
            $this->makeLeaf(2, 100, null, 5),   // teil: 2/5 → PARTIAL
            $this->makeLeaf(3, 100, null, 5),   // voll: 5/5 → FULL
            $this->makeLeaf(4, 100, null, null), // unbegrenzt mit Zusagen → PARTIAL
        ];
        $assignmentCounts = [
            1 => 0,
            2 => 2,
            3 => 5,
            4 => 3,
        ];

        $tree = $aggregator->buildTree($tasks, $assignmentCounts);

        self::assertCount(4, $tree);
        self::assertSame(TaskStatus::EMPTY,   $tree[0]['status']);
        self::assertSame(TaskStatus::PARTIAL, $tree[1]['status']);
        self::assertSame(TaskStatus::FULL,    $tree[2]['status']);
        self::assertSame(TaskStatus::PARTIAL, $tree[3]['status']);
    }

    public function test_buildTree_rollup_propagates_worst_status_to_group(): void
    {
        $aggregator = new TaskTreeAggregator();
        // Gruppe 10 hat drei Kinder: FULL, FULL, EMPTY → Gruppe EMPTY.
        // Gruppe 20 hat zwei Kinder: FULL, PARTIAL → Gruppe PARTIAL.
        // Gruppe 30 hat ein Kind: FULL → Gruppe FULL.
        $tasks = [
            $this->makeGroup(10, 100, null),
            $this->makeLeaf(11, 100, 10, 3),
            $this->makeLeaf(12, 100, 10, 3),
            $this->makeLeaf(13, 100, 10, 3),

            $this->makeGroup(20, 100, null),
            $this->makeLeaf(21, 100, 20, 3),
            $this->makeLeaf(22, 100, 20, 3),

            $this->makeGroup(30, 100, null),
            $this->makeLeaf(31, 100, 30, 3),
        ];
        $assignmentCounts = [
            11 => 3,   // FULL
            12 => 3,   // FULL
            13 => 0,   // EMPTY  -> Gruppe 10: EMPTY (schlechtester)
            21 => 3,   // FULL
            22 => 1,   // PARTIAL -> Gruppe 20: PARTIAL
            31 => 3,   // FULL -> Gruppe 30: FULL
        ];

        $tree = $aggregator->buildTree($tasks, $assignmentCounts);

        // Top-Level: drei Gruppen (nach sort_order sortiert).
        self::assertCount(3, $tree);

        $byId = [];
        foreach ($tree as $node) {
            $byId[$node['task']->getId()] = $node;
        }

        self::assertSame(TaskStatus::EMPTY,   $byId[10]['status']);
        self::assertSame(TaskStatus::PARTIAL, $byId[20]['status']);
        self::assertSame(TaskStatus::FULL,    $byId[30]['status']);
    }

    public function test_buildTree_empty_group_gets_null_status_not_full(): void
    {
        $aggregator = new TaskTreeAggregator();
        // Gruppe ohne Kinder → null, NICHT FULL.
        // worst([]) liefert null; das Feld muss bewusst null bleiben, damit
        // Views kein irrefuehrendes "voll"-Badge rendern.
        $tasks = [$this->makeGroup(10, 100, null)];
        $tree = $aggregator->buildTree($tasks, []);

        self::assertCount(1, $tree);
        // Ohne assignmentCounts sowieso null; aber auch MIT (aber leerer Map)
        // darf die Gruppe keinen Status bekommen, weil keine Kinder da sind,
        // ueber die das Rollup laufen koennte. Der Aggregator setzt
        // countsActive nur bei nicht-leerem Array. Also test erweitert:
        $tree = $aggregator->buildTree($tasks, [999 => 0]); // irrelevante Map
        self::assertNull(
            $tree[0]['status'],
            'Leere Gruppe darf keinen Status haben — weder FULL noch EMPTY.'
        );
    }

    public function test_buildTree_group_with_single_empty_child_is_empty(): void
    {
        $aggregator = new TaskTreeAggregator();
        $tasks = [
            $this->makeGroup(10, 100, null),
            $this->makeLeaf(11, 100, 10, 5),   // 0/5 → EMPTY
        ];
        $tree = $aggregator->buildTree($tasks, [11 => 0]);

        self::assertCount(1, $tree);
        self::assertSame(TaskStatus::EMPTY, $tree[0]['status']);
        self::assertSame(TaskStatus::EMPTY, $tree[0]['children'][0]['status']);
    }

    public function test_buildTree_nested_rollup_goes_up_through_groups(): void
    {
        $aggregator = new TaskTreeAggregator();
        // Gruppe 10 enthaelt Gruppe 20 enthaelt ein leeres Leaf 21.
        // Erwartet: Leaf 21 EMPTY -> Gruppe 20 EMPTY -> Gruppe 10 EMPTY.
        $tasks = [
            $this->makeGroup(10, 100, null),
            $this->makeGroup(20, 100, 10),
            $this->makeLeaf(21, 100, 20, 5),
        ];
        $tree = $aggregator->buildTree($tasks, [21 => 0]);

        self::assertCount(1, $tree);
        $outerGroup = $tree[0];
        $innerGroup = $outerGroup['children'][0];
        $leaf       = $innerGroup['children'][0];

        self::assertSame(TaskStatus::EMPTY, $leaf['status']);
        self::assertSame(TaskStatus::EMPTY, $innerGroup['status']);
        self::assertSame(TaskStatus::EMPTY, $outerGroup['status']);
    }
}
