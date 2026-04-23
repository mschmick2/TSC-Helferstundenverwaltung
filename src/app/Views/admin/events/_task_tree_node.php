<?php
/**
 * Partial: einzelner Knoten im Aufgabenbaum-Editor (Modul 6 I7b1,
 * um Template-Kontext erweitert in I7c Phase 2, um Prefix-Override
 * erweitert in I7e-A Phase 2).
 *
 * Kontext-Flag:
 *   $context = 'event' (Default) oder 'template'. Steuert die Default-
 *   URL-Generierung fuer data-endpoint-*-Attribute. Der JS-Kern
 *   event-task-tree.js liest diese Endpunkte aus den Attributen —
 *   URLs sind nicht im JS fest-kodiert.
 *
 * URL-Prefix-Override:
 *   $urlPrefix (I7e-A Phase 2). Optional. Wenn gesetzt, ueberschreibt
 *   es die Default-Formel und wird als Basis fuer alle data-endpoint-*-
 *   Attribute verwendet. Erwartet mit abschliessendem '/tasks/'.
 *   Beispiel fuer den Organisator-Editor:
 *     $urlPrefix = '/organizer/events/' . $eventId . '/tasks/';
 *   Fehlt die Variable, greift die bisherige Default-Formel (Admin
 *   bzw. Template-URLs). So bleiben bestehende Includes unveraendert.
 *
 * Entity-ID:
 *   $entityId — Event-ID im Event-Kontext, Template-ID im Template-
 *   Kontext. Fuer Rueckwaerts-Kompatibilitaet liest der Partial auch
 *   $eventId, falls $entityId nicht gesetzt ist.
 *
 * Erwartet im Scope:
 *   $node            — Aggregator-Knoten aus (Template)TaskTreeAggregator
 *                      ::buildTree. Struktur identisch; Status-Feld nur
 *                      im Event-Kontext gesetzt.
 *   $depth           — int (0 fuer Top-Level).
 *   $csrfToken       — string (fuer noscript-Forms).
 *   $entityId        — int (Event- oder Template-ID, je nach $context).
 *   $eventId         — int, Rueckwaerts-Fallback wenn $entityId fehlt.
 *   $context         — 'event' | 'template' (Default: 'event').
 *   $urlPrefix       — string|null, Override des URL-Prefixes
 *                      (muss mit '/tasks/' enden).
 *   $renderTaskNode  — Closure fuer rekursiven Aufruf auf Kinder.
 *   $canConvert      — bool.
 *   $canDelete       — bool.
 *
 * @var array $node
 * @var int $depth
 * @var string $csrfToken
 * @var int|null $entityId
 * @var int|null $eventId
 * @var string|null $context
 * @var string|null $urlPrefix
 * @var \Closure $renderTaskNode
 */
use App\Helpers\ViewHelper;

// Context + Entity-ID normalisieren (Rueckwaerts-Kompatibilitaet fuer
// bestehende Event-Include-Stellen, die weiter $eventId setzen).
$context  = $context  ?? 'event';
$entityId = $entityId ?? ($eventId ?? 0);

/** @var \App\Models\EventTask|\App\Models\EventTemplateTask $task */
$task              = $node['task'];
$isGroup           = $task->isGroup();
$taskId            = (int)    $task->getId();
$title             = (string) $task->getTitle();
$description       = (string) ($task->getDescription() ?? '');
$helpersSubtree    = (int)    ($node['helpers_subtree'] ?? 0);
$hoursSubtree      = (float)  ($node['hours_subtree']   ?? 0.0);
$leavesSubtree     = (int)    ($node['leaves_subtree']  ?? 0);
// open_slots_subtree kommt nur im Event-Aggregator; Template-Aggregator
// liefert kein Feld, .. ?? null bleibt null.
$openSlotsSubtree  = $node['open_slots_subtree'] ?? null;
$capacityTarget    = $task->getCapacityTarget();
$hoursDefault      = (float)  $task->getHoursDefault();
$children          = (array)  ($node['children'] ?? []);

$canConvert = $canConvert ?? (
    $isGroup ? empty($children) : true
);
$canDelete  = $canDelete ?? (
    $isGroup ? empty($children) : true
);

// URL-Prefix kontext-abhaengig bauen. 'event' => /admin/events/...,
// 'template' => /admin/event-templates/... Das JS bleibt unveraendert,
// es liest nur die data-endpoint-*-Attribute.
//
// I7e-A Phase 2: Ein explizit gesetztes $urlPrefix vom Container
// (z.B. Organisator-Editor mit '/organizer/events/{id}/tasks/') hat
// Vorrang vor der Default-Formel. Muss auf '/tasks/' enden, weil die
// Endpunkt-Segmente ('reorder', '{taskId}/move', ...) direkt
// angehaengt werden.
$urlPrefix = $urlPrefix ?? (
    $context === 'template'
        ? '/admin/event-templates/' . $entityId . '/tasks/'
        : '/admin/events/'           . $entityId . '/tasks/'
);
$baseUrl    = $urlPrefix . $taskId;
$reorderUrl = $urlPrefix . 'reorder';

// I7b3: Belegungsstatus aus Aggregator (TaskStatus|null). null bedeutet
// keine Farbkodierung — bestehende Border-Regeln greifen weiter.
// Templates haben IMMER null, weil ihr Aggregator keinen Status liefert.
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
            data-endpoint-reorder="<?= ViewHelper::url($reorderUrl) ?>">
            <?php foreach ($children as $child): ?>
                <?php $renderTaskNode($child, $depth + 1); ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</li>
