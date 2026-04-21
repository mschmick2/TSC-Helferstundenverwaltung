<?php
/**
 * Partial: Neue Aufgabe zu Event hinzufuegen.
 * Erwartet: $event (Event), $categories (Category[]) im Scope.
 *
 * @var \App\Models\Event $event
 * @var \App\Models\Category[] $categories
 */
use App\Helpers\ViewHelper;
?>

<form method="POST" action="<?= ViewHelper::url('/admin/events/' . (int) $event->getId() . '/tasks') ?>"
      class="row g-2 p-3 bg-light border rounded">
    <?= ViewHelper::csrfField() ?>

    <div class="col-md-6">
        <label class="form-label">Titel <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="title" maxlength="200" required>
    </div>

    <div class="col-md-3">
        <label class="form-label">Typ</label>
        <select name="task_type" class="form-select">
            <option value="aufgabe">Aufgabe</option>
            <option value="beigabe">Beigabe</option>
        </select>
    </div>

    <div class="col-md-3">
        <label class="form-label">Slot-Modus</label>
        <select name="slot_mode" class="form-select">
            <option value="fix">Fixes Zeitfenster</option>
            <option value="variabel">Variabel (User schlaegt vor)</option>
        </select>
    </div>

    <div class="col-md-12">
        <label class="form-label">Beschreibung</label>
        <textarea name="description" class="form-control" rows="2"></textarea>
    </div>

    <div class="col-md-6">
        <label class="form-label">Kategorie</label>
        <select name="category_id" class="form-select">
            <option value="">- keine -</option>
            <?php foreach (($categories ?? []) as $c): ?>
                <option value="<?= (int) $c->getId() ?>">
                    <?= ViewHelper::e($c->getName()) ?>
                    <?php if ($c->isContribution()): ?> (Beigabe)<?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-md-3">
        <label class="form-label">Start (bei Slot=fix)</label>
        <input type="datetime-local" class="form-control" name="task_start_at">
    </div>

    <div class="col-md-3">
        <label class="form-label">Ende (bei Slot=fix)</label>
        <input type="datetime-local" class="form-control" name="task_end_at">
    </div>

    <div class="col-md-4">
        <label class="form-label">Kapazitaet</label>
        <select name="capacity_mode" class="form-select">
            <option value="unbegrenzt">unbegrenzt</option>
            <option value="ziel">Ziel-Anzahl</option>
            <option value="maximum">Maximum (hart)</option>
        </select>
    </div>

    <div class="col-md-4">
        <label class="form-label">Anzahl (ziel/maximum)</label>
        <input type="number" class="form-control" name="capacity_target" min="1">
    </div>

    <div class="col-md-4">
        <label class="form-label">Standard-Stunden</label>
        <input type="number" class="form-control" name="hours_default" step="0.25" min="0" value="0">
    </div>

    <div class="col-md-12">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Aufgabe anlegen
        </button>
    </div>
</form>
