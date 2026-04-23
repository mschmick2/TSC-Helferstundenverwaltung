<?php
/**
 * Modul 6 I7e-A Phase 2 — Shared Sidebar des non-modalen Editors.
 * Wird sowohl vom Admin-Editor (/admin/events/{id}/editor) als auch
 * vom Organisator-Editor (/organizer/events/{id}/editor) eingebunden.
 *
 * Drei Panels:
 *   1. Event-Metadaten (Titel, Zeitraum, Ort, Status, Organisatoren).
 *   2. Belegungs-Zusammenfassung (Offene Slots, Zusagen, Gruppen, Stunden).
 *   3. Chronologische Aufgabenliste (nur Leafs, nach Startzeit sortiert) —
 *      jede Zeile ist ein Button, der beim Klick den zugehoerigen Knoten
 *      im Tree-Widget hervorhebt (siehe event-task-tree.js Scroll-
 *      Highlight-Listener).
 *
 * @var \App\Models\Event $event
 * @var array $organizers      Array aus EventOrganizerRepository::listForEvent:
 *                             [ ['user_id' => int, 'vorname' => string,
 *                                'nachname' => string, 'email' => string], ... ]
 * @var array $summary         ['leaf_count' => int, 'group_count' => int,
 *                              'helpers_total' => int, 'open_slots' => int,
 *                              'open_slots_known' => bool,
 *                              'hours_default_total' => float,
 *                              'status_counts' => ['empty' => int,
 *                                                  'partial' => int,
 *                                                  'full' => int]]
 * @var array $flatList        Liste der Leaf-Tasks (EventTask-Objekte) plus
 *                             Zusatzfelder 'status', 'helpers', 'open_slots',
 *                             'ancestor_path'. Schon nach start_at sortiert.
 */
use App\Helpers\ViewHelper;
use App\Models\Event;
use App\Models\TaskStatus;

$statusLabels = [
    Event::STATUS_ENTWURF          => 'Entwurf',
    Event::STATUS_VEROEFFENTLICHT  => 'Veroeffentlicht',
    Event::STATUS_ABGESCHLOSSEN    => 'Abgeschlossen',
    Event::STATUS_ABGESAGT         => 'Abgesagt',
];
$statusBadgeClass = [
    Event::STATUS_ENTWURF          => 'bg-secondary',
    Event::STATUS_VEROEFFENTLICHT  => 'bg-success',
    Event::STATUS_ABGESCHLOSSEN    => 'bg-primary',
    Event::STATUS_ABGESAGT         => 'bg-danger',
];
$statusCode  = $event->getStatus();
$statusLabel = $statusLabels[$statusCode] ?? $statusCode;
$statusClass = $statusBadgeClass[$statusCode] ?? 'bg-light text-dark';

$startDt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $event->getStartAt());
$endDt   = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $event->getEndAt());
$startStr = $startDt ? $startDt->format('d.m.Y H:i') : $event->getStartAt();
$endStr   = $endDt   ? $endDt->format('d.m.Y H:i')   : $event->getEndAt();

$summary = $summary ?? [
    'leaf_count'          => 0,
    'group_count'         => 0,
    'helpers_total'       => 0,
    'open_slots'          => 0,
    'open_slots_known'    => false,
    'hours_default_total' => 0.0,
    'status_counts'       => ['empty' => 0, 'partial' => 0, 'full' => 0],
];
$statusCounts = $summary['status_counts'] ?? ['empty' => 0, 'partial' => 0, 'full' => 0];
?>
<aside class="editor-sidebar" aria-label="Event-Editor-Sidebar">

    <!-- Panel 1: Event-Metadaten -->
    <section class="card mb-3">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                Event
            </h2>
        </div>
        <div class="card-body small">
            <div class="mb-2">
                <div class="text-muted text-uppercase small mb-1">Titel</div>
                <div class="fw-semibold"><?= ViewHelper::e($event->getTitle()) ?></div>
            </div>

            <div class="mb-2">
                <div class="text-muted text-uppercase small mb-1">Zeitraum</div>
                <div>
                    <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>
                    <?= ViewHelper::e($startStr) ?><br>
                    <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>
                    <?= ViewHelper::e($endStr) ?>
                </div>
            </div>

            <?php if ($event->getLocation() !== null && $event->getLocation() !== ''): ?>
                <div class="mb-2">
                    <div class="text-muted text-uppercase small mb-1">Ort</div>
                    <div>
                        <i class="bi bi-geo-alt me-1" aria-hidden="true"></i>
                        <?= ViewHelper::e($event->getLocation()) ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mb-2">
                <div class="text-muted text-uppercase small mb-1">Status</div>
                <span class="badge <?= ViewHelper::e($statusClass) ?>">
                    <?= ViewHelper::e($statusLabel) ?>
                </span>
            </div>

            <div class="mb-0">
                <div class="text-muted text-uppercase small mb-1">
                    Organisator<?= count($organizers ?? []) === 1 ? '' : 'en' ?>
                    (<?= count($organizers ?? []) ?>)
                </div>
                <?php if (empty($organizers)): ?>
                    <div class="text-muted fst-italic">Keine zugeordnet.</div>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($organizers as $org): ?>
                            <li>
                                <i class="bi bi-person me-1" aria-hidden="true"></i>
                                <?= ViewHelper::e(
                                    ($org['nachname'] ?? '') . ', ' . ($org['vorname'] ?? '')
                                ) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Panel 2: Belegungs-Zusammenfassung -->
    <section class="card mb-3">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">
                <i class="bi bi-bar-chart-line" aria-hidden="true"></i>
                Belegung
            </h2>
        </div>
        <div class="card-body small">
            <dl class="row mb-0 g-2">
                <dt class="col-7 text-muted fw-normal">Aufgaben / Gruppen</dt>
                <dd class="col-5 text-end mb-0">
                    <?= (int) ($summary['leaf_count'] ?? 0) ?>
                    / <?= (int) ($summary['group_count'] ?? 0) ?>
                </dd>

                <dt class="col-7 text-muted fw-normal">Aktive Zusagen</dt>
                <dd class="col-5 text-end mb-0">
                    <?= (int) ($summary['zusagen_aktiv'] ?? 0) ?>
                </dd>

                <dt class="col-7 text-muted fw-normal">Helfer-Soll</dt>
                <dd class="col-5 text-end mb-0">
                    <?= (int) ($summary['helpers_total'] ?? 0) ?>
                </dd>

                <dt class="col-7 text-muted fw-normal">Offene Slots</dt>
                <dd class="col-5 text-end mb-0">
                    <?php if (!empty($summary['open_slots_known'])): ?>
                        <?= (int) ($summary['open_slots'] ?? 0) ?>
                    <?php else: ?>
                        <span class="text-muted" title="Mindestens eine Aufgabe ohne Kapazitaetsgrenze">&mdash;</span>
                    <?php endif; ?>
                </dd>

                <dt class="col-7 text-muted fw-normal">Summe Standard-Stunden</dt>
                <dd class="col-5 text-end mb-0">
                    <?= number_format((float) ($summary['hours_default_total'] ?? 0), 2, ',', '.') ?> h
                </dd>
            </dl>

            <hr class="my-2">

            <div class="d-flex flex-wrap gap-1">
                <span class="task-status-badge task-status-badge--empty">
                    <?= (int) ($statusCounts['empty'] ?? 0) ?> keine Zusage
                </span>
                <span class="task-status-badge task-status-badge--partial">
                    <?= (int) ($statusCounts['partial'] ?? 0) ?> teilweise
                </span>
                <span class="task-status-badge task-status-badge--full">
                    <?= (int) ($statusCounts['full'] ?? 0) ?> voll
                </span>
            </div>
        </div>
    </section>

    <!-- Panel 3: Chronologische Aufgabenliste -->
    <section class="card">
        <div class="card-header py-2">
            <h2 class="h6 mb-0">
                <i class="bi bi-list-task" aria-hidden="true"></i>
                Aufgaben chronologisch
                <span class="text-muted small">(<?= count($flatList ?? []) ?>)</span>
            </h2>
        </div>
        <div class="card-body small p-0">
            <?php if (empty($flatList)): ?>
                <div class="p-3 text-muted fst-italic">
                    Noch keine Aufgaben mit Startzeit.
                </div>
            <?php else: ?>
                <ul class="list-group list-group-flush editor-sidebar__chronlist mb-0">
                    <?php foreach ($flatList as $entry):
                        /** @var \App\Models\EventTask $task */
                        $task      = $entry['task'];
                        $taskStatus = $entry['status'] ?? null;
                        $startAtStr = $task->getStartAt();
                        $startAtDt  = $startAtStr !== null
                            ? \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startAtStr)
                            : false;
                        $timeLabel = $startAtDt
                            ? $startAtDt->format('d.m. H:i')
                            : '—';
                        $statusCss = $taskStatus instanceof TaskStatus
                            ? ' ' . $taskStatus->cssClass()
                            : '';
                        $ariaLabel = $taskStatus instanceof TaskStatus
                            ? $taskStatus->ariaLabel()
                            : 'ohne Statuskennzeichnung';
                    ?>
                        <li class="list-group-item list-group-item-action p-0<?= $statusCss ?>">
                            <button type="button"
                                    class="btn btn-link text-decoration-none text-body w-100 text-start p-2"
                                    data-sidebar-scroll-target="<?= (int) $task->getId() ?>"
                                    aria-label="Zu Aufgabe <?= ViewHelper::e($task->getTitle()) ?> springen (<?= ViewHelper::e($ariaLabel) ?>)">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <span class="fw-semibold flex-grow-1">
                                        <?= ViewHelper::e($task->getTitle()) ?>
                                    </span>
                                    <span class="text-muted small text-nowrap">
                                        <?= ViewHelper::e($timeLabel) ?>
                                    </span>
                                </div>
                                <?php if (!empty($entry['ancestor_path'])): ?>
                                    <div class="text-muted small">
                                        <?= ViewHelper::e(implode(' › ', $entry['ancestor_path'])) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($taskStatus instanceof TaskStatus): ?>
                                    <span class="task-status-badge task-status-badge--<?= ViewHelper::e($taskStatus->value) ?> mt-1">
                                        <?= ViewHelper::e($taskStatus->badgeLabel()) ?>
                                    </span>
                                <?php endif; ?>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
</aside>
