<?php
/**
 * @var \App\Models\Event $event
 * @var \App\Models\User[] $users
 * @var int[] $organizerIds
 */
use App\Helpers\ViewHelper;
?>

<h1 class="h3 mb-3"><i class="bi bi-pencil-square"></i> Event bearbeiten</h1>

<form method="POST" action="<?= ViewHelper::url('/admin/events/' . (int) $event->getId()) ?>"
      class="row g-3 needs-validation" novalidate>
    <?= ViewHelper::csrfField() ?>
    <input type="hidden" name="version" value="<?= (int) $event->getVersion() ?>"><!-- Modul 7 I3: Optimistic Locking -->

    <div class="col-md-12">
        <label for="title" class="form-label">Titel <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="title" name="title"
               value="<?= ViewHelper::e($event->getTitle()) ?>" maxlength="200" required>
    </div>

    <div class="col-md-12">
        <label for="description" class="form-label">Beschreibung</label>
        <textarea class="form-control" id="description" name="description" rows="3"><?= ViewHelper::e($event->getDescription()) ?></textarea>
    </div>

    <div class="col-md-6">
        <label for="location" class="form-label">Ort</label>
        <input type="text" class="form-control" id="location" name="location"
               value="<?= ViewHelper::e($event->getLocation()) ?>" maxlength="500">
    </div>

    <div class="col-md-6">
        <label for="cancel_deadline_hours" class="form-label">Storno-Deadline (h)</label>
        <input type="number" class="form-control" id="cancel_deadline_hours"
               name="cancel_deadline_hours" value="<?= (int) $event->getCancelDeadlineHours() ?>" min="0">
    </div>

    <div class="col-md-6">
        <label for="start_at" class="form-label">Start <span class="text-danger">*</span></label>
        <input type="datetime-local" class="form-control" id="start_at" name="start_at"
               value="<?= ViewHelper::e(substr($event->getStartAt(), 0, 16)) ?>" required>
    </div>

    <div class="col-md-6">
        <label for="end_at" class="form-label">Ende <span class="text-danger">*</span></label>
        <input type="datetime-local" class="form-control" id="end_at" name="end_at"
               value="<?= ViewHelper::e(substr($event->getEndAt(), 0, 16)) ?>" required>
    </div>

    <div class="col-md-12">
        <label for="organizer_ids" class="form-label">Organisator(en) <span class="text-danger">*</span></label>
        <select multiple class="form-select" id="organizer_ids" name="organizer_ids[]" size="6" required>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u->getId() ?>"
                    <?= in_array((int) $u->getId(), $organizerIds, true) ? 'selected' : '' ?>>
                    <?= ViewHelper::e($u->getNachname() . ', ' . $u->getVorname()) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-12">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-circle"></i> Speichern
        </button>
        <a href="<?= ViewHelper::url('/admin/events/' . (int) $event->getId()) ?>" class="btn btn-secondary">Abbrechen</a>
    </div>
</form>
