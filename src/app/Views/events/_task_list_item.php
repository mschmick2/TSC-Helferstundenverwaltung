<?php
/**
 * Modul 6 I7b4 Phase 2 — Sub-Partial: einzelner Leaf in der
 * chronologischen Task-Liste. Wird pro Leaf von _task_list_by_date.php
 * eingebunden.
 *
 * Input (als Scope-Variablen — vom einbindenden Partial gesetzt):
 *   @var array{
 *       task: \App\Models\EventTask,
 *       status: ?\App\Models\TaskStatus,
 *       helpers: int,
 *       open_slots: ?int,
 *       ancestor_path: list<string>
 *   } $leaf
 *   @var \App\Models\Event $event
 *   @var bool $linkTaskTitles
 *
 * Farbkodierung: die .task-status-*-Klassen aus I7b3 ueberschreiben den
 * Default-Border-Left (I7b3-Konvention). Wenn $leaf['status'] === null,
 * bleibt der Default-Border aus dem Listen-Styling.
 */

use App\Helpers\ViewHelper;

/** @var array $leaf */
/** @var \App\Models\Event $event */
/** @var bool $linkTaskTitles */

$task          = $leaf['task'];
$status        = $leaf['status'] ?? null;
$ancestorPath  = $leaf['ancestor_path'] ?? [];
$openSlots     = $leaf['open_slots'] ?? null;
$helpers       = (int) ($leaf['helpers'] ?? 0);
$statusCssClass = $status !== null ? $status->cssClass() : '';

// Start/End-Zeit aus String-DATETIME in ein DateTimeImmutable-Format
// umformen, damit wir H:i statt des vollen "YYYY-MM-DD HH:MM:SS" zeigen.
$startAtStr = $task->getStartAt();
$endAtStr   = $task->getEndAt();
$startAtDt  = $startAtStr !== null
    ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startAtStr)
    : false;
$endAtDt    = $endAtStr !== null
    ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endAtStr)
    : false;

// taken = helpers - open_slots (wenn open_slots nicht null). Bei
// unbegrenzter Kapazitaet (capacity_target === null) ist open_slots
// ebenfalls null — dann entfaellt die "x von y offen"-Zeile zugunsten
// eines "Beliebig viele Helfer"-Hinweises.
$capacityTarget = $task->getCapacityTarget();
$hasCapacity    = $capacityTarget !== null && $openSlots !== null;
$taken          = $hasCapacity ? max(0, $helpers - (int) $openSlots) : 0;
?>

<li class="task-list-item <?= ViewHelper::e($statusCssClass) ?>"
    <?php if ($status !== null): ?>
    aria-label="<?= ViewHelper::e($status->ariaLabel()) ?>"
    <?php endif; ?>>

    <div class="task-list-item-header">
        <?php if ($linkTaskTitles): ?>
            <strong>
                <a href="/admin/events/<?= (int) $event->getId() ?>">
                    <?= ViewHelper::e($task->getTitle()) ?>
                </a>
            </strong>
        <?php else: ?>
            <strong><?= ViewHelper::e($task->getTitle()) ?></strong>
        <?php endif; ?>

        <?php if ($status !== null): ?>
            <span class="task-status-badge task-status-badge--<?= ViewHelper::e($status->value) ?>"
                  title="<?= ViewHelper::e($status->ariaLabel()) ?>">
                <?= ViewHelper::e($status->badgeLabel()) ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if (!empty($ancestorPath)): ?>
        <p class="task-list-item-breadcrumb text-muted">
            <?php foreach ($ancestorPath as $i => $ancestorTitle): ?>
                <?php if ($i > 0): ?> &gt; <?php endif; ?>
                <?= ViewHelper::e($ancestorTitle) ?>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>

    <?php if ($startAtDt !== false): ?>
        <p class="task-list-item-time text-muted">
            <i class="bi bi-clock" aria-hidden="true"></i>
            <?= ViewHelper::e($startAtDt->format('H:i')) ?>
            <?php if ($endAtDt !== false): ?>
                &ndash; <?= ViewHelper::e($endAtDt->format('H:i')) ?>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <p class="task-list-item-capacity text-muted">
        <?php if (!$hasCapacity): ?>
            Beliebig viele Helfer
        <?php else: ?>
            <?= $taken ?> von <?= (int) $capacityTarget ?> besetzt
            <?php if ($openSlots > 0): ?>
                <span class="text-body">(<?= (int) $openSlots ?> offen)</span>
            <?php endif; ?>
        <?php endif; ?>
        &middot; <?= ViewHelper::e(ViewHelper::formatHours($task->getHoursDefault())) ?> h
    </p>
</li>
