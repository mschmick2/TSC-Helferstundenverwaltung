<?php
/**
 * @var \App\Models\Event[] $events
 * @var \App\Models\EventTaskAssignment[] $pendingReviews
 * @var array<int, array{task:?\App\Models\EventTask, event:?\App\Models\Event, assignee:?\App\Models\User, replacement:?\App\Models\User}> $reviewContext
 */
use App\Helpers\ViewHelper;
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

    <!-- Eigene Events-Uebersicht -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <h2 class="h5 mb-0"><i class="bi bi-calendar-event"></i> Meine Events</h2>
            </div>
            <div class="card-body">
                <?php if (empty($events)): ?>
                    <p class="text-muted mb-0">Du bist bei keinem Event als Organisator eingetragen.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($events as $e): ?>
                            <li class="mb-2 pb-2 border-bottom">
                                <strong><?= ViewHelper::e($e->getTitle()) ?></strong>
                                <br>
                                <small class="text-muted">
                                    <?= ViewHelper::formatDateTime($e->getStartAt()) ?>
                                    <span class="badge bg-<?= $e->isPublished() ? 'success' : ($e->isFinal() ? 'dark' : 'secondary') ?>">
                                        <?= ViewHelper::e($e->getStatus()) ?>
                                    </span>
                                </small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
