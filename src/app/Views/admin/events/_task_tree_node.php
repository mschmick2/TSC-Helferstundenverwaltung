<?php
/**
 * Partial: einzelner Knoten im Aufgabenbaum-Editor (Modul 6 I7b1).
 *
 * Erwartet im Scope:
 *   $node            — Aggregator-Knoten aus TaskTreeAggregator::buildTree:
 *                      [
 *                        'task' => EventTask,
 *                        'children' => array<Aggregator-Knoten>,
 *                        'helpers_subtree' => int,
 *                        'hours_subtree' => float,
 *                        'leaves_subtree' => int,
 *                        'open_slots_subtree' => int|null,
 *                      ]
 *                      Task-eigene Felder (title, description, slot_mode etc.)
 *                      werden ueber $node['task']->getX() gelesen, NICHT als
 *                      flache Keys am $node-Array.
 *   $depth           — int (0 fuer Top-Level).
 *   $csrfToken       — string (fuer noscript-Forms).
 *   $eventId         — int (fuer URL-Aufbau).
 *   $renderTaskNode  — Closure (fuer rekursiven Aufruf auf Kinder; liefert
 *                      der Container in edit.php, nicht dieses Partial).
 *   $canConvert      — bool (true wenn konvertierbar; false bei aktiven
 *                      Kindern bzw. aktiven Assignments — per Aggregator-
 *                      Daten ermittelt).
 *   $canDelete       — bool (wie canConvert; Delete-Regeln sind gleich).
 *
 * XSS-Schutz: Alle Freitext-Felder ($task->getTitle(), $task->getDescription())
 * werden per htmlspecialchars/ViewHelper::e() encodiert.
 *
 * @var array $node
 * @var int $depth
 * @var string $csrfToken
 * @var int $eventId
 * @var \Closure $renderTaskNode
 */
use App\Helpers\ViewHelper;

/** @var \App\Models\EventTask $task */
$task              = $node['task'];
$isGroup           = $task->isGroup();
$taskId            = (int)    $task->getId();
$title             = (string) $task->getTitle();
$description       = (string) ($task->getDescription() ?? '');
$helpersSubtree    = (int)    ($node['helpers_subtree'] ?? 0);
$hoursSubtree      = (float)  ($node['hours_subtree']   ?? 0.0);
$leavesSubtree     = (int)    ($node['leaves_subtree']  ?? 0);
$openSlotsSubtree  = $node['open_slots_subtree'] ?? null;  // darf null bleiben
$capacityTarget    = $task->getCapacityTarget();
$hoursDefault      = (float)  $task->getHoursDefault();
$children          = (array)  ($node['children'] ?? []);

$canConvert = $canConvert ?? (
    $isGroup ? empty($children) : true
);
$canDelete  = $canDelete ?? (
    $isGroup ? empty($children) : true
);

$baseUrl = '/admin/events/' . $eventId . '/tasks/' . $taskId;

// I7b3: Belegungsstatus aus Aggregator (TaskStatus|null). null bedeutet
// keine Farbkodierung — bestehende Border-Regeln aus I7b1 greifen weiter.
$status = $node['status'] ?? null;
?>
<li class="task-node<?= $isGroup ? ' task-node--group' : ' task-node--leaf' ?><?= $status !== null ? ' ' . $status->cssClass() : '' ?>"
    data-task-id="<?= $taskId ?>"
    data-is-group="<?= $isGroup ? '1' : '0' ?>"
    <?php if ($status !== null): ?>
    aria-label="<?= ViewHelper::e($status->ariaLabel()) ?>"
    <?php endif; ?>
    data-endpoint-move="<?= ViewHelper::url($baseUrl . '/move') ?>"
    data-endpoint-convert="<?= ViewHelper::url($baseUrl . '/convert') ?>"
    data-endpoint-delete="<?= ViewHelper::url($baseUrl . '/tree-delete') ?>"
    data-endpoint-edit="<?= ViewHelper::url($baseUrl . '/edit') ?>"
    data-endpoint-update="<?= ViewHelper::url($baseUrl) ?>">
    <div class="task-node__row d-flex align-items-center gap-2 py-1">
        <span class="task-node__handle text-muted"
              data-sortable-handle
              role="button"
              tabindex="0"
              aria-label="Ziehen zum Verschieben">
            <i class="bi bi-grip-vertical" aria-hidden="true"></i>
        </span>

        <span class="task-node__icon" aria-hidden="true">
            <?php if ($isGroup): ?>
                <i class="bi bi-folder2-open text-warning"></i>
            <?php else: ?>
                <i class="bi bi-card-checklist text-primary"></i>
            <?php endif; ?>
        </span>

        <button type="button"
                class="task-node__edit-trigger btn btn-link text-decoration-none text-body flex-grow-1 text-start px-1 py-0"
                data-task-id="<?= $taskId ?>"
                data-endpoint-edit="<?= ViewHelper::url($baseUrl . '/edit') ?>"
                data-action="edit"
                draggable="false"
                title="<?= ViewHelper::e($title) ?>">
            <?= ViewHelper::e($title) ?>
        </button>

        <?php if ($status !== null): ?>
            <span class="task-status-badge task-status-badge--<?= $status->value ?>"
                  title="<?= ViewHelper::e($status->ariaLabel()) ?>">
                <?= ViewHelper::e($status->badgeLabel()) ?>
            </span>
        <?php endif; ?>

        <span class="task-node__badges small text-muted d-none d-md-inline">
            <?php if ($isGroup): ?>
                <span class="badge bg-light text-dark border me-1"
                      title="Helfer im gesamten Teilbaum (aktive Zusagen)">
                    <i class="bi bi-people" aria-hidden="true"></i>
                    <?= $helpersSubtree ?>
                </span>
                <?php if ($openSlotsSubtree !== null): ?>
                    <span class="badge bg-light text-dark border me-1"
                          title="Offene Slots im Teilbaum">
                        <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                        <?= (int) $openSlotsSubtree ?>
                    </span>
                <?php endif; ?>
                <span class="badge bg-light text-dark border"
                      title="Summe Standard-Stunden im Teilbaum">
                    <i class="bi bi-clock" aria-hidden="true"></i>
                    <?= number_format($hoursSubtree, 2, ',', '.') ?> h
                </span>
            <?php else: ?>
                <?php if ($capacityTarget !== null): ?>
                    <span class="badge bg-light text-dark border me-1"
                          title="Kapazitaet / Ziel">
                        <i class="bi bi-people" aria-hidden="true"></i>
                        <?= (int) $capacityTarget ?>
                    </span>
                <?php endif; ?>
                <span class="badge bg-light text-dark border"
                      title="Standard-Stunden">
                    <i class="bi bi-clock" aria-hidden="true"></i>
                    <?= number_format($hoursDefault, 2, ',', '.') ?> h
                </span>
            <?php endif; ?>
        </span>

        <span class="task-node__actions btn-group btn-group-sm" role="group"
              aria-label="Knoten-Aktionen">
            <?php if ($isGroup): ?>
                <button type="button" class="btn btn-outline-secondary"
                        data-action="add-child"
                        data-parent-task-id="<?= $taskId ?>"
                        title="Unter-Aufgabe hinzufuegen"
                        aria-label="Unter-Aufgabe hinzufuegen">
                    <i class="bi bi-plus-circle" aria-hidden="true"></i>
                </button>
            <?php endif; ?>

            <button type="button" class="btn btn-outline-secondary"
                    data-action="edit"
                    data-endpoint-edit="<?= ViewHelper::url($baseUrl . '/edit') ?>"
                    title="Bearbeiten"
                    aria-label="Bearbeiten">
                <i class="bi bi-pencil" aria-hidden="true"></i>
            </button>

            <button type="button"
                    class="btn btn-outline-secondary<?= $canConvert ? '' : ' disabled' ?>"
                    data-action="convert"
                    data-target="<?= $isGroup ? 'leaf' : 'group' ?>"
                    data-endpoint-convert="<?= ViewHelper::url($baseUrl . '/convert') ?>"
                    <?= $canConvert ? '' : 'disabled aria-disabled="true"' ?>
                    title="<?= $isGroup
                        ? ($canConvert
                            ? 'In Aufgabe konvertieren'
                            : 'Nicht konvertierbar: Gruppe enthaelt Kinder')
                        : ($canConvert
                            ? 'In Gruppe konvertieren'
                            : 'Nicht konvertierbar: aktive Zusagen vorhanden') ?>"
                    aria-label="Konvertieren">
                <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
            </button>

            <button type="button"
                    class="btn btn-outline-danger<?= $canDelete ? '' : ' disabled' ?>"
                    data-action="delete"
                    data-endpoint-delete="<?= ViewHelper::url($baseUrl . '/tree-delete') ?>"
                    <?= $canDelete ? '' : 'disabled aria-disabled="true"' ?>
                    title="<?= $canDelete
                        ? 'Loeschen'
                        : ($isGroup
                            ? 'Loeschen abgelehnt: aktive Kinder'
                            : 'Loeschen abgelehnt: aktive Zusagen') ?>"
                    aria-label="Loeschen">
                <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
        </span>

        <noscript>
            <span class="noscript-hint small text-muted ms-2">
                (Drag &amp; Drop + Modal-Editor benoetigen aktiviertes JavaScript.
                Fuer noscript-Fallback bitte die Detail-Seite des Events nutzen.)
            </span>
        </noscript>
    </div>

    <?php if ($description !== ''): ?>
        <div class="task-node__description small text-muted ps-4 pe-2 pb-1">
            <?= nl2br(ViewHelper::e($description)) ?>
        </div>
    <?php endif; ?>

    <?php if ($isGroup): ?>
        <ul class="task-tree-children list-unstyled ps-4"
            data-parent-task-id="<?= $taskId ?>"
            data-endpoint-reorder="<?= ViewHelper::url('/admin/events/' . $eventId . '/tasks/reorder') ?>">
            <?php foreach ($children as $child): ?>
                <?php $renderTaskNode($child, $depth + 1); ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</li>
