<?php
/**
 * Partial: Read-Only-Darstellung eines Aufgabenbaum-Knotens
 * (Modul 6 I7b1 Phase 3c, um Template-Kontext erweitert in I7c Phase 2).
 *
 * Kontext-Flag:
 *   $context = 'event' (Default) oder 'template'.
 *   - event:    Leaf-Zeit als "15.05.2026 10:00 – 12:00" (absolute
 *               DATETIME-Felder start_at/end_at).
 *   - template: Leaf-Zeit als "+30 min – +2 h 0 min" (relative Offsets
 *               default_offset_minutes_start/end zum Event-Start).
 *   - Assignments und Farbkodierung gibt es nur im Event-Kontext.
 *
 * Erwartet im Scope:
 *   $node                  — Aggregator-Knoten aus (Template)TaskTreeAggregator
 *                            ::buildTree. Struktur identisch; Status-Feld
 *                            und open_slots_subtree nur im Event-Kontext.
 *   $depth                 — int (0 fuer Top-Level).
 *   $context               — 'event' | 'template' (Default: 'event').
 *   $renderReadonlyNode    — Closure fuer rekursiven Aufruf.
 *
 * @var array $node
 * @var int $depth
 * @var string|null $context
 * @var \Closure $renderReadonlyNode
 */
use App\Helpers\ViewHelper;
use App\Models\EventTask;

$context = $context ?? 'event';

/** @var \App\Models\EventTask|\App\Models\EventTemplateTask $task */
$task             = $node['task'];
$isGroup          = $task->isGroup();
$children         = (array) ($node['children'] ?? []);
$helpersSubtree   = (int)   ($node['helpers_subtree']   ?? 0);
$hoursSubtree     = (float) ($node['hours_subtree']     ?? 0.0);
$leavesSubtree    = (int)   ($node['leaves_subtree']    ?? 0);
$openSlotsSubtree = $node['open_slots_subtree'] ?? null;
// I7b3: Belegungsstatus aus Aggregator (TaskStatus|null). null laesst die
// bestehenden Border-Regeln greifen. Templates haben IMMER null (keine
// Assignments).
$status           = $node['status'] ?? null;

// Leaf-spezifische Felder kontext-abhaengig auslesen.
// Die beiden Task-Modelle sind nicht signatur-kompatibel: EventTask hat
// hasFixedSlot()/getStartAt()/getEndAt()/isContribution(), EventTemplateTask
// hat sie nicht. Wir bauen die Leaf-Anzeige-Daten hier einmal auf.
$isContribution = false;
$isFixSlot      = false;
$leafTimeLabel  = '';
if (!$isGroup) {
    $taskType       = $task->getTaskType();
    $isContribution = $taskType === EventTask::TYPE_BEIGABE;
    $slotMode       = $task->getSlotMode();
    $isFixSlot      = $slotMode === EventTask::SLOT_FIX;

    if ($isFixSlot) {
        if ($context === 'template') {
            /** @var \App\Models\EventTemplateTask $task */
            $offsetStart = $task->getDefaultOffsetMinutesStart();
            $offsetEnd   = $task->getDefaultOffsetMinutesEnd();
            $leafTimeLabel = ViewHelper::formatOffsetMinutes($offsetStart)
                . ' – ' . ViewHelper::formatOffsetMinutes($offsetEnd);
        } else {
            /** @var \App\Models\EventTask $task */
            $leafTimeLabel = ViewHelper::formatDateTime($task->getStartAt())
                . ' – ' . ViewHelper::formatDateTime($task->getEndAt());
        }
    }
}
?>
<li class="task-node-readonly<?= $isGroup ? ' task-node-readonly--group' : ' task-node-readonly--leaf' ?><?= $status !== null ? ' ' . $status->cssClass() : '' ?>"
    <?php if ($status !== null): ?>
    aria-label="<?= ViewHelper::e($status->ariaLabel()) ?>"
    <?php endif; ?>>
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
            <?php if ($status !== null): ?>
                <span class="task-status-badge task-status-badge--<?= $status->value ?>"
                      title="<?= ViewHelper::e($status->ariaLabel()) ?>">
                    <?= ViewHelper::e($status->badgeLabel()) ?>
                </span>
            <?php endif; ?>
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
            <?php if ($isContribution): ?>
                <span class="badge bg-info">Beigabe</span>
            <?php else: ?>
                <span class="badge bg-primary">Aufgabe</span>
            <?php endif; ?>
            <?php if ($status !== null): ?>
                <span class="task-status-badge task-status-badge--<?= $status->value ?>"
                      title="<?= ViewHelper::e($status->ariaLabel()) ?>">
                    <?= ViewHelper::e($status->badgeLabel()) ?>
                </span>
            <?php endif; ?>
            <span class="task-node-readonly__summary small text-muted">
                <?php if ($isFixSlot): ?>
                    <i class="bi bi-clock" aria-hidden="true"></i>
                    <?= ViewHelper::e($leafTimeLabel) ?>
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
