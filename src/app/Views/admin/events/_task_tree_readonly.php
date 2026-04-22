<?php
/**
 * Partial: Read-Only-Darstellung eines Aufgabenbaum-Knotens
 * (Modul 6 I7b1 Phase 3c).
 *
 * Erwartet im Scope:
 *   $node                  — Aggregator-Knoten aus TaskTreeAggregator::buildTree:
 *                            ['task' => EventTask, 'children' => array,
 *                             'helpers_subtree' => int, 'hours_subtree' => float,
 *                             'leaves_subtree' => int,
 *                             'open_slots_subtree' => int|null].
 *   $depth                 — int (0 fuer Top-Level; aktuell nur fuer
 *                            semantische Zwecke, Einrueckung laeuft ueber CSS).
 *   $renderReadonlyNode    — Closure fuer rekursiven Aufruf auf Kinder
 *                            (vom Container in show.php geliefert).
 *
 * Reine Anzeige: keine Drag-Handles, keine Action-Buttons, keine
 * data-endpoint-Attribute, keine Editier-Interaktion. Bearbeiten
 * laeuft ueber den separaten Editor in admin/events/edit.php.
 *
 * XSS-Schutz: alle Freitext-Felder (title, description) per
 * ViewHelper::e() encodieren.
 *
 * @var array $node
 * @var int $depth
 * @var \Closure $renderReadonlyNode
 */
use App\Helpers\ViewHelper;

/** @var \App\Models\EventTask $task */
$task             = $node['task'];
$isGroup          = $task->isGroup();
$children         = (array) ($node['children'] ?? []);
$helpersSubtree   = (int)   ($node['helpers_subtree']   ?? 0);
$hoursSubtree     = (float) ($node['hours_subtree']     ?? 0.0);
$leavesSubtree    = (int)   ($node['leaves_subtree']    ?? 0);
$openSlotsSubtree = $node['open_slots_subtree'] ?? null;
?>
<li class="task-node-readonly<?= $isGroup ? ' task-node-readonly--group' : ' task-node-readonly--leaf' ?>">
    <div class="task-node-readonly__row d-flex align-items-center flex-wrap gap-2 py-1">
        <span class="task-node-readonly__icon" aria-hidden="true">
            <?php if ($isGroup): ?>
                <i class="bi bi-folder2-open text-warning"></i>
            <?php else: ?>
                <i class="bi bi-card-checklist text-primary"></i>
            <?php endif; ?>
        </span>

        <?php if ($isGroup): ?>
            <strong class="task-node-readonly__title">
                <?= ViewHelper::e($task->getTitle()) ?>
            </strong>
            <span class="badge bg-light text-secondary border">Gruppe</span>
            <span class="task-node-readonly__summary small text-muted">
                <?= $leavesSubtree ?>
                <?= $leavesSubtree === 1 ? 'Aufgabe' : 'Aufgaben' ?>
                &middot;
                <?= $helpersSubtree ?> Helfer
                &middot;
                <?= number_format($hoursSubtree, 2, ',', '.') ?> h
                <?php if ($openSlotsSubtree !== null && $openSlotsSubtree > 0): ?>
                    &middot;
                    <span class="text-warning">
                        <?= (int) $openSlotsSubtree ?> offen
                    </span>
                <?php endif; ?>
            </span>
        <?php else: /* Leaf */ ?>
            <span class="task-node-readonly__title">
                <?= ViewHelper::e($task->getTitle()) ?>
            </span>
            <?php if ($task->isContribution()): ?>
                <span class="badge bg-info">Beigabe</span>
            <?php else: ?>
                <span class="badge bg-primary">Aufgabe</span>
            <?php endif; ?>
            <span class="task-node-readonly__summary small text-muted">
                <?php if ($task->hasFixedSlot()): ?>
                    <i class="bi bi-clock" aria-hidden="true"></i>
                    <?= ViewHelper::formatDateTime($task->getStartAt()) ?>
                    &ndash; <?= ViewHelper::formatDateTime($task->getEndAt()) ?>
                <?php else: ?>
                    <i class="bi bi-clock" aria-hidden="true"></i>
                    <em>frei</em>
                <?php endif; ?>
                &middot;
                <?php
                $capTarget = $task->getCapacityTarget();
                $capMode   = $task->getCapacityMode();
                ?>
                <?php if ($capTarget !== null): ?>
                    <?= (int) $capTarget ?> Helfer
                    <?php if ($capMode !== 'ziel'): ?>
                        (<?= ViewHelper::e($capMode) ?>)
                    <?php endif; ?>
                <?php else: ?>
                    unbegrenzt
                <?php endif; ?>
                &middot;
                <?= ViewHelper::formatHours($task->getHoursDefault()) ?> h
            </span>
        <?php endif; ?>
    </div>

    <?php if ($task->getDescription() !== null && $task->getDescription() !== ''): ?>
        <div class="task-node-readonly__description small text-muted ps-4 pe-2 pb-1">
            <?= nl2br(ViewHelper::e($task->getDescription())) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($children)): ?>
        <ul class="task-tree-readonly list-unstyled">
            <?php foreach ($children as $child): ?>
                <?php $renderReadonlyNode($child, $depth + 1); ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</li>
