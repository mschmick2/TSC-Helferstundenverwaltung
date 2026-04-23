<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\EventTask;
use App\Models\TaskStatus;
use App\Services\TaskTreeAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer TaskTreeAggregator::flattenToList (Modul 6 I7b4).
 *
 * Die neue Methode wandelt den Tree aus buildTree() in eine flache Liste
 * von Leaves in Depth-First-Baum-Reihenfolge um. Gruppen entfallen; ihr
 * Titel wandert in das ancestor_path-Feld der ihnen untergeordneten
 * Leaves.
 *
 * Die Tests decken drei Aspekt-Gruppen ab:
 *   (1) Traversierung und Struktur: DFS-Reihenfolge, Gruppen-Filterung,
 *       ancestor_path-Aufbau, Edge-Cases.
 *   (2) Format-Invarianten: start_at/end_at als String im MySQL-DATETIME-
 *       Format (YYYY-MM-DD HH:MM:SS). Phase-2-Partial nutzt substr() auf
 *       diesem Format fuer die Tages-Gruppierung; der Test zwingt zur
 *       Anpassung, falls das Repository-Format jemals kippt.
 *   (3) Variable-Slot-Handling: slot_mode='variabel' → start_at null.
 */
final class TaskTreeAggregatorFlattenTest extends TestCase
{
    // =========================================================================
    // Fixture-Helfer
    // =========================================================================

    private function makeLeaf(
        int $id,
        int $eventId,
        ?int $parentId,
        ?int $capacityTarget = 1,
        int $sortOrder = 0,
        ?string $startAt = null,
        ?string $endAt = null,
        string $title = 'Leaf'
    ): EventTask {
        return EventTask::fromArray([
            'id'              => $id,
            'event_id'        => $eventId,
            'parent_task_id'  => $parentId,
            'is_group'        => 0,
            'title'           => $title . '-' . $id,
            'task_type'       => EventTask::TYPE_AUFGABE,
            'slot_mode'       => $startAt !== null ? EventTask::SLOT_FIX : EventTask::SLOT_VARIABEL,
            'start_at'        => $startAt,
            'end_at'          => $endAt,
            'capacity_mode'   => $capacityTarget === null ? EventTask::CAP_UNBEGRENZT : EventTask::CAP_ZIEL,
            'capacity_target' => $capacityTarget,
            'hours_default'   => 1.0,
            'sort_order'      => $sortOrder,
        ]);
    }

    private function makeGroup(
        int $id,
        int $eventId,
        ?int $parentId,
        int $sortOrder = 0,
        string $title = 'Group'
    ): EventTask {
        return EventTask::fromArray([
            'id'              => $id,
            'event_id'        => $eventId,
            'parent_task_id'  => $parentId,
            'is_group'        => 1,
            'title'           => $title . '-' . $id,
            'task_type'       => EventTask::TYPE_AUFGABE,
            'slot_mode'       => null,
            'capacity_mode'   => EventTask::CAP_UNBEGRENZT,
            'capacity_target' => null,
            'hours_default'   => 0.0,
            'sort_order'      => $sortOrder,
        ]);
    }

    // =========================================================================
    // Block 1: Traversierung + Struktur
    // =========================================================================

    public function test_flattenToList_returns_only_leaves(): void
    {
        $aggregator = new TaskTreeAggregator();
        $tasks = [
            $this->makeGroup(10, 100, null),
            $this->makeLeaf(11, 100, 10),
            $this->makeLeaf(12, 100, 10),
        ];

        $list = $aggregator->flattenToList($tasks);

        self::assertCount(2, $list, 'Gruppen erscheinen nicht im Flatten-Output.');
        foreach ($list as $entry) {
            self::assertFalse($entry['task']->isGroup(), 'Kein Eintrag ist eine Gruppe.');
        }
    }

    public function test_flattenToList_preserves_depth_first_order(): void
    {
        $aggregator = new TaskTreeAggregator();
        // Baum:
        //   A (Gruppe, sort=1)
        //     A1 (Leaf, sort=1)
        //     A2 (Leaf, sort=2)
        //   B (Leaf, sort=2)
        //   C (Gruppe, sort=3)
        //     C1 (Leaf, sort=1)
        $tasks = [
            $this->makeGroup(1, 100, null, 1, 'A'),
            $this->makeLeaf(11, 100, 1, 1, 1, null, null, 'A1'),
            $this->makeLeaf(12, 100, 1, 1, 2, null, null, 'A2'),
            $this->makeLeaf(2, 100, null, 1, 2, null, null, 'B'),
            $this->makeGroup(3, 100, null, 3, 'C'),
            $this->makeLeaf(31, 100, 3, 1, 1, null, null, 'C1'),
        ];

        $list = $aggregator->flattenToList($tasks);

        $ids = array_map(static fn(array $e) => (int) $e['task']->getId(), $list);
        // Erwartet: A1, A2, B, C1 (DFS unter Beruecksichtigung von sort_order)
        self::assertSame([11, 12, 2, 31], $ids);
    }

    public function test_flattenToList_respects_sort_order_at_each_depth(): void
    {
        $aggregator = new TaskTreeAggregator();
        // Umgekehrte Eingabe-Reihenfolge — Aggregator muss trotzdem nach
        // sort_order sortieren.
        $tasks = [
            $this->makeLeaf(3, 100, null, 1, 3, null, null, 'Dritter'),
            $this->makeLeaf(1, 100, null, 1, 1, null, null, 'Erster'),
            $this->makeLeaf(2, 100, null, 1, 2, null, null, 'Zweiter'),
        ];

        $list = $aggregator->flattenToList($tasks);
        $ids  = array_map(static fn(array $e) => (int) $e['task']->getId(), $list);

        self::assertSame([1, 2, 3], $ids);
    }

    public function test_flattenToList_ancestor_path_contains_all_ancestors(): void
    {
        $aggregator = new TaskTreeAggregator();
        // Baum: Gruppe-1 > Gruppe-10 > Leaf-100
        $tasks = [
            $this->makeGroup(1, 100, null, 1, 'Root'),
            $this->makeGroup(10, 100, 1, 1, 'Inner'),
            $this->makeLeaf(100, 100, 10, 1, 1, null, null, 'Deep'),
        ];

        $list = $aggregator->flattenToList($tasks);

        self::assertCount(1, $list);
        self::assertSame(
            ['Root-1', 'Inner-10'],
            $list[0]['ancestor_path'],
            'ancestor_path enthaelt Wurzel-Gruppe zuerst, dann die innere Gruppe.'
        );
    }

    public function test_flattenToList_top_level_leaf_has_empty_ancestor_path(): void
    {
        $aggregator = new TaskTreeAggregator();
        $tasks = [$this->makeLeaf(1, 100, null, 1)];

        $list = $aggregator->flattenToList($tasks);

        self::assertCount(1, $list);
        self::assertSame([], $list[0]['ancestor_path']);
    }

    public function test_flattenToList_empty_tree_returns_empty_array(): void
    {
        $aggregator = new TaskTreeAggregator();
        $list = $aggregator->flattenToList([]);

        self::assertSame([], $list);
    }

    public function test_flattenToList_without_assignmentCounts_sets_status_null(): void
    {
        $aggregator = new TaskTreeAggregator();
        $tasks = [$this->makeLeaf(1, 100, null, 5)];

        $list = $aggregator->flattenToList($tasks);

        self::assertCount(1, $list);
        self::assertNull(
            $list[0]['status'],
            'Ohne assignmentCounts bleibt status null (buildTree-Kontrakt).'
        );
    }

    public function test_flattenToList_with_assignmentCounts_sets_status_per_leaf(): void
    {
        $aggregator = new TaskTreeAggregator();
        $tasks = [
            $this->makeLeaf(1, 100, null, 5),  // 0/5 → EMPTY
            $this->makeLeaf(2, 100, null, 5),  // 5/5 → FULL
        ];
        $counts = [1 => 0, 2 => 5];

        $list = $aggregator->flattenToList($tasks, $counts);

        // Sortierung nach id (sort_order default 0, id 1 < id 2)
        self::assertSame(TaskStatus::EMPTY, $list[0]['status']);
        self::assertSame(TaskStatus::FULL, $list[1]['status']);
    }

    public function test_flattenToList_helpers_and_open_slots_carry_through(): void
    {
        $aggregator = new TaskTreeAggregator();
        $tasks = [$this->makeLeaf(1, 100, null, 4)];  // target=4
        $counts = [1 => 1];                           // 1 Zusage → 3 offen

        $list = $aggregator->flattenToList($tasks, $counts);

        self::assertCount(1, $list);
        self::assertSame(4, $list[0]['helpers']);
        self::assertSame(3, $list[0]['open_slots']);
    }

    // =========================================================================
    // Block 2: Format-Invarianten (Phase-2-substr-Annahme)
    // =========================================================================

    public function test_flattenToList_start_at_is_string_with_expected_format(): void
    {
        $aggregator = new TaskTreeAggregator();
        $tasks = [
            $this->makeLeaf(
                1,
                100,
                null,
                1,
                0,
                '2026-05-15 10:00:00',
                '2026-05-15 12:00:00'
            ),
        ];

        $list = $aggregator->flattenToList($tasks);

        self::assertCount(1, $list);
        $startAt = $list[0]['task']->getStartAt();
        self::assertIsString(
            $startAt,
            'start_at muss String bleiben — das Phase-2-Partial nutzt substr().'
        );
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $startAt,
            'MySQL-DATETIME-Format erwartet — Abweichung wuerde '
            . 'substr(string, 0, 10) im Partial kippen.'
        );
    }

    public function test_flattenToList_end_at_format_matches_start_at(): void
    {
        $aggregator = new TaskTreeAggregator();
        $tasks = [
            $this->makeLeaf(
                1,
                100,
                null,
                1,
                0,
                '2026-05-15 10:00:00',
                '2026-05-15 12:30:00'
            ),
        ];

        $list = $aggregator->flattenToList($tasks);
        $endAt = $list[0]['task']->getEndAt();

        self::assertIsString($endAt);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $endAt
        );
    }

    // =========================================================================
    // Block 3: Variable-Slot-Handling
    // =========================================================================

    public function test_flattenToList_variable_slot_task_has_null_start_at(): void
    {
        $aggregator = new TaskTreeAggregator();
        $tasks = [$this->makeLeaf(1, 100, null, 1)]; // startAt null default → slot=variabel

        $list = $aggregator->flattenToList($tasks);

        self::assertCount(1, $list);
        self::assertNull(
            $list[0]['task']->getStartAt(),
            'Variable-Slot-Tasks haben start_at=null; Partial packt sie in '
            . 'die Sektion "Ohne feste Zeitvorgabe".'
        );
        self::assertSame(EventTask::SLOT_VARIABEL, $list[0]['task']->getSlotMode());
    }

    public function test_flattenToList_mixed_fix_and_variable_in_same_list(): void
    {
        $aggregator = new TaskTreeAggregator();
        $tasks = [
            $this->makeLeaf(1, 100, null, 1, 1, '2026-05-15 10:00:00', '2026-05-15 12:00:00'),
            $this->makeLeaf(2, 100, null, 1, 2), // variabel
        ];

        $list = $aggregator->flattenToList($tasks);

        self::assertCount(2, $list);
        // Die DFS-Reihenfolge bleibt unsortiert nach Zeit; Sortierung macht
        // der Controller/View. Leaf 1 hat fix-Slot, Leaf 2 variabel.
        self::assertSame(EventTask::SLOT_FIX, $list[0]['task']->getSlotMode());
        self::assertSame(EventTask::SLOT_VARIABEL, $list[1]['task']->getSlotMode());
    }
}
