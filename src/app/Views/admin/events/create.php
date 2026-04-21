<?php
/**
 * @var \App\Models\User[] $users
 * @var \App\Models\Category[] $categories
 */
use App\Helpers\ViewHelper;
?>

<h1 class="h3 mb-3"><i class="bi bi-calendar-plus"></i> Neues Event</h1>

<form method="POST" action="<?= ViewHelper::url('/admin/events') ?>" class="row g-3 needs-validation" novalidate>
    <?= ViewHelper::csrfField() ?>

    <div class="col-md-12">
        <label for="title" class="form-label">Titel <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="title" name="title"
               value="<?= ViewHelper::old('title') ?>" maxlength="200" required>
    </div>

    <div class="col-md-12">
        <label for="description" class="form-label">Beschreibung</label>
        <textarea class="form-control" id="description" name="description" rows="3"><?= ViewHelper::old('description') ?></textarea>
    </div>

    <div class="col-md-6">
        <label for="location" class="form-label">Ort (optional)</label>
        <input type="text" class="form-control" id="location" name="location"
               value="<?= ViewHelper::old('location') ?>" maxlength="500">
    </div>

    <div class="col-md-6">
        <label for="cancel_deadline_hours" class="form-label">Storno-Deadline (h vor Event)</label>
        <input type="number" class="form-control" id="cancel_deadline_hours"
               name="cancel_deadline_hours"
               value="<?= (int) \App\Models\Event::DEFAULT_CANCEL_DEADLINE_HOURS ?>" min="0">
    </div>

    <div class="col-md-6">
        <label for="start_at" class="form-label">Start <span class="text-danger">*</span></label>
        <input type="datetime-local" class="form-control" id="start_at" name="start_at"
               value="<?= ViewHelper::old('start_at') ?>" required>
    </div>

    <div class="col-md-6">
        <label for="end_at" class="form-label">Ende <span class="text-danger">*</span></label>
        <input type="datetime-local" class="form-control" id="end_at" name="end_at"
               value="<?= ViewHelper::old('end_at') ?>" required>
    </div>

    <div class="col-md-12">
        <label for="organizer_ids" class="form-label">
            Organisator(en) <span class="text-danger">*</span>
        </label>
        <select multiple class="form-select" id="organizer_ids" name="organizer_ids[]" size="6" required>
            <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u->getId() ?>">
                    <?= ViewHelper::e($u->getNachname() . ', ' . $u->getVorname()) ?>
                    (<?= ViewHelper::e($u->getEmail()) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <div class="form-text">Mehrfachauswahl mit Strg/Cmd. Mindestens 1 Organisator noetig.</div>
    </div>

    <div class="col-12">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-circle"></i> Speichern (als Entwurf)
        </button>
        <a href="<?= ViewHelper::url('/admin/events') ?>" class="btn btn-secondary">Abbrechen</a>
    </div>
    <small class="text-muted">
        <span class="text-danger">*</span> Pflichtfelder. Aufgaben werden nach dem Anlegen auf der Detail-Seite hinzugefuegt.
    </small>
</form>
