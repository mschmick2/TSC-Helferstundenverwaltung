<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\EventTemplateTask;
use App\Services\TemplateTaskTreeAggregator;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests fuer TemplateTaskTreeAggregator (Modul 6 I7c).
 *
 * Aggregator ist die schlankere Schwester von TaskTreeAggregator:
 *   - Kein Status-Rollup (Templates haben keine Assignments).
 *   - Kein open_slots_subtree.
 *   - Zeitfelder sind Integer-Offsets (default_offset_minutes_start/end),
 *     nicht DATETIME-Strings.
 *
 * Die Tests decken die in-Memory-Transformation aus flacher Task-Liste
 * zu verschachtelter Knoten-Struktur ab und sichern die Feld-Shape fuer
 * das Rendering in _task_tree_readonly.php ($context='template').
 */
final class TemplateTaskTreeAggregatorTest extends TestCase
{
    private function makeLeaf(
        int $id,
        int $templateId,
        ?int $parentTaskId,
        ?int $capacityTarget = null,
        float $hours = 1.0,
        int $sortOrder = 0,
        ?int $offsetStart = null,
        ?int $offsetEnd = null
    ): EventTemplateTask {
        return EventTemplateTask::fromArray([
            'id'                           => $id,
            'template_id'                  => $templateId,
            'parent_template_task_id'      => $parentTaskId,
            'is_group'                     => 0,
            'title'                        => 'Leaf-' . $id,
            'task_type'                    => 'aufgabe',
            'slot_mode'                    => $offsetStart !== null ? 'fix' : 'variabel',
            'default_offset_minutes_start' => $offsetStart,
            'default_offset_minutes_end'   => $offsetEnd,
            'capacity_mode'                => $capacityTarget === null ? 'unbegrenzt' : 'ziel',
            'capacity_target'              => $capacityTarget,
            'hours_default'                => $hours,
            'sort_order'                   => $sortOrder,
        ]);
    }

    private function makeGroup(
        int $id,
        int $templateId,
        ?int $parentTaskId,
        int $sortOrder = 0
    ): EventTemplateTask {
        return EventTemplateTask::fromArray([
            'id'                           => $id,
            'template_id'                  => $templateId,
            'parent_template_task_id'      => $parentTaskId,
            'is_group'                     => 1,
            'title'                        => 'Group-' . $id,
            'task_type'                    => 'aufgabe',
            'slot_mode'                    => null,
            'capacity_mode'                => 'unbegrenzt',
            'capacity_target'              => null,
            'hours_default'                => 0.0,
            'sort_order'                   => $sortOrder,
        ]);
    }

    // =========================================================================
    // buildTree
    // =========================================================================

    public function test_buildTree_returns_empty_for_no_tasks(): void
    {
        $agg = new TemplateTaskTreeAggregator();
        self::assertSame([], $agg->buildTree([]));
    }

    public function test_buildTree_respects_parent_child_hierarchy(): void
    {
        $agg = new TemplateTaskTreeAggregator();
        $tasks = [
            $this->makeGroup(10, 100, null),
            $this->makeLeaf(11, 100, 10),
            $this->makeLeaf(12, 100, 10),
            $this->makeLeaf(2, 100, null),
        ];
        $tree = $agg->buildTree($tasks);

        self::assertCount(2, $tree, 'Zwei Top-Level-Knoten: Gruppe 10 und Leaf 2.');
        $topIds = array_map(fn($n) => (int) $n['task']->getId(), $tree);
        self::assertEqualsCanonicalizing([10, 2], $topIds);

        $groupNode = $tree[0]['task']->getId() === 10 ? $tree[0] : $tree[1];
        self::assertCount(2, $groupNode['children']);
    }

    public function test_buildTree_respects_sort_order_at_each_depth(): void
    {
        $agg = new TemplateTaskTreeAggregator();
        $tasks = [
            $this->makeLeaf(3, 100, null, null, 1.0, 3),
            $this->makeLeaf(1, 100, null, null, 1.0, 1),
            $this->makeLeaf(2, 100, null, null, 1.0, 2),
        ];
        $tree = $agg->buildTree($tasks);
        $ids = array_map(fn($n) => (int) $n['task']->getId(), $tree);
        self::assertSame([1, 2, 3], $ids);
    }

    public function test_buildTree_has_no_status_field(): void
    {
        // Templates haben keinen Belegungs-Status (keine Assignments).
        // Der Aggregator liefert gar kein status-Feld — der ViewHelper-
        // Zugriff per $node['status'] ?? null fallbackt auf null, was
        // das Partial korrekt behandelt.
        $agg = new TemplateTaskTreeAggregator();
        $tasks = [$this->makeLeaf(1, 100, null, 5)];
        $tree = $agg->buildTree($tasks);

        self::assertCount(1, $tree);
        self::assertArrayNotHasKey(
            'status',
            $tree[0],
            'Template-Aggregator-Knoten hat kein status-Feld. Views nutzen '
            . '$node[\'status\'] ?? null — null bedeutet keine Farbkodierung.'
        );
        self::assertArrayNotHasKey(
            'open_slots_subtree',
            $tree[0],
            'Template-Aggregator hat kein open_slots_subtree — Templates '
            . 'haben keine Zusagen.'
        );
    }

    public function test_buildTree_preserves_offset_minutes(): void
    {
        $agg = new TemplateTaskTreeAggregator();
        $tasks = [
            $this->makeLeaf(1, 100, null, 5, 1.0, 0, 30, 90),
        ];
        $tree = $agg->buildTree($tasks);

        self::assertCount(1, $tree);
        self::assertSame(30, $tree[0]['task']->getDefaultOffsetMinutesStart());
        self::assertSame(90, $tree[0]['task']->getDefaultOffsetMinutesEnd());
    }

    public function test_buildTree_aggregates_subtree_helpers_hours_leaves(): void
    {
        $agg = new TemplateTaskTreeAggregator();
        $tasks = [
            $this->makeGroup(10, 100, null),
            $this->makeLeaf(11, 100, 10, 3, 2.0),
            $this->makeLeaf(12, 100, 10, 5, 1.5),
        ];
        $tree = $agg->buildTree($tasks);

        self::assertCount(1, $tree);
        $group = $tree[0];
        self::assertSame(8, $group['helpers_subtree'], '3 + 5 = 8 Helfer im Subtree.');
        self::assertEqualsWithDelta(3.5, $group['hours_subtree'], 0.001);
        self::assertSame(2, $group['leaves_subtree']);
    }

    public function test_buildTree_handles_deeply_nested_hierarchy(): void
    {
        $agg = new TemplateTaskTreeAggregator();
        $tasks = [
            $this->makeGroup(1, 100, null),
            $this->makeGroup(2, 100, 1),
            $this->makeGroup(3, 100, 2),
            $this->makeLeaf(4, 100, 3, 1),
        ];
        $tree = $agg->buildTree($tasks);

        $root = $tree[0];
        self::assertSame(1, (int) $root['task']->getId());
        self::assertSame(2, (int) $root['children'][0]['task']->getId());
        self::assertSame(3, (int) $root['children'][0]['children'][0]['task']->getId());
        self::assertSame(4, (int) $root['children'][0]['children'][0]['children'][0]['task']->getId());
    }

    // =========================================================================
    // Pfad-Helfer
    // =========================================================================

    public function test_getAncestorPath_returns_array_of_ancestors(): void
    {
        $agg = new TemplateTaskTreeAggregator();
        $tasks = [
            $this->makeGroup(1, 100, null),
            $this->makeGroup(2, 100, 1),
            $this->makeLeaf(3, 100, 2),
        ];

        $path = $agg->getAncestorPath(3, $tasks);

        self::assertCount(2, $path);
        self::assertSame('Group-1', $path[0]['title']);
        self::assertSame('Group-2', $path[1]['title']);
        self::assertTrue($path[0]['is_group']);
    }

    public function test_getPathString_joins_ancestors_and_self(): void
    {
        $agg = new TemplateTaskTreeAggregator();
        $tasks = [
            $this->makeGroup(1, 100, null),
            $this->makeLeaf(2, 100, 1),
        ];

        self::assertSame('Group-1 > Leaf-2', $agg->getPathString(2, $tasks));
    }

    public function test_getPathString_for_top_level_returns_just_title(): void
    {
        $agg = new TemplateTaskTreeAggregator();
        $tasks = [$this->makeLeaf(1, 100, null)];

        self::assertSame('Leaf-1', $agg->getPathString(1, $tasks));
    }
}
