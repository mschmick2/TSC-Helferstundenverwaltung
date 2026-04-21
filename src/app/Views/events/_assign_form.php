<?php
/**
 * Partial: Uebernahme-Formular pro Task.
 * Erwartet $event, $t im Scope.
 *
 * @var \App\Models\Event $event
 * @var \App\Models\EventTask $t
 */
use App\Helpers\ViewHelper;
use App\Models\EventTask;

$formAction = ViewHelper::url(
    '/events/' . (int) $event->getId() . '/tasks/' . (int) $t->getId() . '/assign'
);
?>

<form method="POST" action="<?= $formAction ?>">
    <?= ViewHelper::csrfField() ?>

    <?php if ($t->getSlotMode() === EventTask::SLOT_VARIABEL): ?>
        <div class="row g-1 mb-2">
            <div class="col-6">
                <input type="datetime-local" class="form-control form-control-sm"
                       name="proposed_start" required
                       aria-label="Dein Zeit-Vorschlag Start"
                       min="<?= ViewHelper::e(substr($event->getStartAt(), 0, 16)) ?>"
                       max="<?= ViewHelper::e(substr($event->getEndAt(), 0, 16)) ?>">
            </div>
            <div class="col-6">
                <input type="datetime-local" class="form-control form-control-sm"
                       name="proposed_end" required
                       aria-label="Dein Zeit-Vorschlag Ende"
                       min="<?= ViewHelper::e(substr($event->getStartAt(), 0, 16)) ?>"
                       max="<?= ViewHelper::e(substr($event->getEndAt(), 0, 16)) ?>">
            </div>
        </div>
        <small class="text-muted d-block mb-2">Dein Vorschlag - wird vom Organisator bestaetigt.</small>
    <?php endif; ?>

    <button type="submit" class="btn btn-primary btn-sm w-100">
        <i class="bi bi-check2-circle"></i> Uebernehmen
    </button>
</form>
