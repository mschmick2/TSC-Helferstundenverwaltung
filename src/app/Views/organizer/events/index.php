<?php
/**
 * @var \App\Models\Event[] $events
 * @var \App\Models\EventTaskAssignment[] $pendingReviews
 * @var array<int, array{task:?\App\Models\EventTask, event:?\App\Models\Event, assignee:?\App\Models\User, replacement:?\App\Models\User}> $reviewContext
 * @var array<int, array{tasks:array, total_target:int, total_filled:int, total_open:int, has_unlimited:bool, percentage:int}> $eventSummaries
 */
use App\Helpers\ViewHelper;
use App\Models\EventTask;
use App\Models\EventTaskAssignment;
?>

<h1 class="h3 mb-3"><i class="bi bi-people"></i> Als Organisator</h1>

<div class="row g-3">
    <!-- Review-Queue -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h2 class="h5 mb-0">
                    <i class="bi bi-inbox"></i> Zu pruefen
                    <span class="badge bg-warning"><?= count($pendingReviews) ?></span>
                </h2>
            </div>
            <div class="card-body">
                <?php if (empty($pendingReviews)): ?>
                    <p class="text-muted mb-0">Keine offenen Vorgaenge.</p>
                <?php else: ?>
                    <?php foreach ($pendingReviews as $a):
                        $ctx = $reviewContext[$a->getId()] ?? [];
                        $task = $ctx['task'] ?? null;
                        $event = $ctx['event'] ?? null;
                        $assignee = $ctx['assignee'] ?? null;
                        $replacement = $ctx['replacement'] ?? null;
                        require __DIR__ . '/../../organizer/_review_card.php';
                    ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Eigene Events-Uebersicht mit Sachstand -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <h2 class="h5 mb-0"><i class="bi bi-calendar-event"></i> Meine Events</h2>
            </div>
            <div class="card-body">
                <?php if (empty($events)): ?>
                    <p class="text-muted mb-0">Du bist bei keinem Event als Organisator eingetragen.</p>
                <?php else: ?>
                    <?php foreach ($events as $e):
                        $sum = $eventSummaries[(int) $e->getId()] ?? null;
                        $hasTasks = $sum !== null && count($sum['tasks']) > 0;
                        $collapseId = 'eventSummary-' . (int) $e->getId();
                    ?>
                        <div class="border rounded p-2 mb-3">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <div>
                                    <strong><?= ViewHelper::e($e->getTitle()) ?></strong>
                                    <br>
                                    <small class="text-muted"><?= ViewHelper::formatDateTime($e->getStartAt()) ?></small>
                                </div>
                                <span class="badge bg-<?= $e->isPublished() ? 'success' : ($e->isFinal() ? 'dark' : 'secondary') ?>">
                                    <?= ViewHelper::e($e->getStatus()) ?>
                                </span>
                            </div>

                            <?php if ($treeEditorEnabled ?? false): ?>
                                <a href="<?= ViewHelper::url('/organizer/events/' . (int) $e->getId() . '/editor') ?>"
                                   class="btn btn-outline-primary btn-sm mb-2">
                                    <i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>
                                    Editor-Ansicht
                                </a>
                            <?php endif; ?>

                            <?php if (!$hasTasks): ?>
                                <small class="text-muted d-block">Keine Aufgaben definiert.</small>
                            <?php else: ?>
                                <?php if ($sum['total_target'] > 0): ?>
                                    <div class="progress mb-1" style="height: 8px;" role="progressbar"
                                         aria-valuenow="<?= (int) $sum['percentage'] ?>"
                                         aria-valuemin="0" aria-valuemax="100"
                                         aria-label="Belegung">
                                        <div class="progress-bar <?= $sum['total_open'] === 0 ? 'bg-success' : 'bg-info' ?>"
                                             style="width: <?= (int) $sum['percentage'] ?>%;"></div>
                                    </div>
                                <?php endif; ?>
                                <small class="d-block mb-1">
                                    <strong><?= (int) $sum['total_filled'] ?></strong> belegt
                                    <?php if ($sum['total_target'] > 0): ?>
                                        &middot; <span class="<?= $sum['total_open'] > 0 ? 'text-warning' : 'text-success' ?>">
                                            <strong><?= (int) $sum['total_open'] ?></strong> offen
                                        </span>
                                        von <?= (int) $sum['total_target'] ?>
                                    <?php endif; ?>
                                    <?php if ($sum['has_unlimited']): ?>
                                        <span class="text-muted">(+ unbegrenzte Aufgaben)</span>
                                    <?php endif; ?>
                                </small>

                                <button type="button"
                                        class="btn btn-link btn-sm p-0 text-decoration-none"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#<?= ViewHelper::e($collapseId) ?>"
                                        aria-expanded="false"
                                        aria-controls="<?= ViewHelper::e($collapseId) ?>">
                                    <i class="bi bi-caret-down-fill"></i> Aufgaben-Details
                                </button>

                                <div class="collapse mt-2" id="<?= ViewHelper::e($collapseId) ?>">
                                    <table class="table table-sm table-borderless mb-0">
                                        <thead>
                                            <tr class="text-muted small">
                                                <th>Aufgabe</th>
                                                <th class="text-end">Belegt</th>
                                                <th class="text-end">Offen</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($sum['tasks'] as $row):
                                                $t = $row['task'];
                                                $open = $row['open'];
                                                $filled = $row['filled'];
                                                $target = $row['target'];
                                            ?>
                                                <tr>
                                                    <td><?= ViewHelper::e($t->getTitle()) ?></td>
                                                    <td class="text-end">
                                                        <?= (int) $filled ?><?= $target !== null ? ' / ' . (int) $target : '' ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php if ($row['mode'] === EventTask::CAP_UNBEGRENZT): ?>
                                                            <span class="text-muted">&infin;</span>
                                                        <?php elseif ($open === null): ?>
                                                            <span class="text-muted">-</span>
                                                        <?php elseif ($open === 0): ?>
                                                            <span class="badge bg-success">voll</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning text-dark"><?= (int) $open ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
