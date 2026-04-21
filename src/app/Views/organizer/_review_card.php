<?php
/**
 * Partial: Ein Review-Item (Zeitfenster-Vorschlag oder Storno-Anfrage).
 * Erwartet $a, $task, $event, $assignee, $replacement im Scope.
 *
 * @var \App\Models\EventTaskAssignment $a
 * @var ?\App\Models\EventTask $task
 * @var ?\App\Models\Event $event
 * @var ?\App\Models\User $assignee
 * @var ?\App\Models\User $replacement
 */
use App\Helpers\ViewHelper;
use App\Models\EventTaskAssignment;

$isTimeReview = $a->getStatus() === EventTaskAssignment::STATUS_VORGESCHLAGEN;
$isCancelReview = $a->getStatus() === EventTaskAssignment::STATUS_STORNO_ANGEFRAGT;
$prefix = $isTimeReview ? 'approve-time' : 'approve-cancel';
$prefixReject = $isTimeReview ? 'reject-time' : 'reject-cancel';
?>

<div class="border-start border-4 <?= $isTimeReview ? 'border-warning' : 'border-danger' ?> ps-3 mb-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
        <div>
            <strong>
                <?php if ($isTimeReview): ?>
                    <i class="bi bi-hourglass-split"></i> Zeitfenster-Vorschlag
                <?php else: ?>
                    <i class="bi bi-x-circle"></i> Storno-Anfrage
                <?php endif; ?>
            </strong>
            <br>
            <?php if ($event !== null && $task !== null): ?>
                <span class="text-muted">
                    <?= ViewHelper::e($event->getTitle()) ?> &mdash; <?= ViewHelper::e($task->getTitle()) ?>
                </span>
            <?php endif; ?>
        </div>
        <?php if ($assignee !== null): ?>
            <small class="text-muted">
                <i class="bi bi-person"></i>
                <?= ViewHelper::e($assignee->getVorname() . ' ' . $assignee->getNachname()) ?>
            </small>
        <?php endif; ?>
    </div>

    <?php if ($isTimeReview && $a->getProposedStart() !== null): ?>
        <p class="small mb-2">
            Vorgeschlagen:
            <strong><?= ViewHelper::formatDateTime($a->getProposedStart()) ?></strong>
            &ndash;
            <strong><?= ViewHelper::formatDateTime($a->getProposedEnd()) ?></strong>
        </p>
    <?php endif; ?>

    <?php if ($isCancelReview && $replacement !== null): ?>
        <p class="small mb-2">
            Vorgeschlagener Ersatz:
            <strong><?= ViewHelper::e($replacement->getVorname() . ' ' . $replacement->getNachname()) ?></strong>
        </p>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-2">
        <form method="POST"
              action="<?= ViewHelper::url('/organizer/assignments/' . (int) $a->getId() . '/' . $prefix) ?>"
              class="d-inline">
            <?= ViewHelper::csrfField() ?>
            <button type="submit" class="btn btn-success btn-sm">
                <i class="bi bi-check"></i> Freigeben
            </button>
        </form>

        <button type="button" class="btn btn-outline-danger btn-sm"
                data-bs-toggle="collapse"
                data-bs-target="#rejectForm<?= (int) $a->getId() ?>">
            <i class="bi bi-x"></i> Ablehnen
        </button>
    </div>

    <div class="collapse mt-2" id="rejectForm<?= (int) $a->getId() ?>">
        <form method="POST"
              action="<?= ViewHelper::url('/organizer/assignments/' . (int) $a->getId() . '/' . $prefixReject) ?>"
              class="row g-2">
            <?= ViewHelper::csrfField() ?>
            <div class="col-md-12">
                <label class="form-label small">Begruendung (Pflicht)</label>
                <input type="text" name="reason" class="form-control form-control-sm" required>
            </div>
            <div class="col-md-12">
                <button type="submit" class="btn btn-danger btn-sm">Ablehnen mit Begruendung</button>
            </div>
        </form>
    </div>
</div>
