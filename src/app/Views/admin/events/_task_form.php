<?php
/**
 * Partial: Aufgabe/Beigabe anlegen ODER bearbeiten.
 *
 * Erwartet:
 *   $event      (Event)        im Scope
 *   $categories (Category[])   im Scope
 *
 * Optional:
 *   $task       (EventTask|null)  gesetzt = Edit-Modus, sonst = Create-Modus
 *   $formUid    (string|null)     eindeutiges Suffix fuer id-Attribute, damit
 *                                 pro Zeile eigene Labels entstehen
 *
 * @var \App\Models\Event $event
 * @var \App\Models\Category[] $categories
 * @var \App\Models\EventTask|null $task
 * @var string|null $formUid
 */
use App\Helpers\ViewHelper;

$task = $task ?? null;
$isEdit = $task !== null;
$uid = $formUid ?? ($isEdit ? 'edit-' . (int) $task->getId() : 'new');

$actionUrl = $isEdit
    ? '/admin/events/' . (int) $event->getId() . '/tasks/' . (int) $task->getId() . '/update'
    : '/admin/events/' . (int) $event->getId() . '/tasks';

$vTitle       = $isEdit ? $task->getTitle() : '';
$vDescription = $isEdit ? ($task->getDescription() ?? '') : '';
$vTaskType    = $isEdit ? $task->getTaskType() : \App\Models\EventTask::TYPE_AUFGABE;
$vSlotMode    = $isEdit ? $task->getSlotMode() : \App\Models\EventTask::SLOT_FIX;
$vCatId       = $isEdit ? $task->getCategoryId() : null;
$vStartAt     = $isEdit && $task->getStartAt() ? substr($task->getStartAt(), 0, 16) : '';
$vEndAt       = $isEdit && $task->getEndAt()   ? substr($task->getEndAt(),   0, 16) : '';
$vCapacityMode   = $isEdit ? $task->getCapacityMode() : \App\Models\EventTask::CAP_UNBEGRENZT;
$vCapacityTarget = $isEdit ? ($task->getCapacityTarget() ?? '') : '';
$vHoursDefault   = $isEdit ? $task->getHoursDefault() : 0;
?>

<form method="POST" action="<?= ViewHelper::url($actionUrl) ?>"
      class="row g-2 p-3 bg-light border rounded">
    <?= ViewHelper::csrfField() ?>

    <div class="col-md-6">
        <label class="form-label" for="task-title-<?= $uid ?>">Titel <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="task-title-<?= $uid ?>" name="title" maxlength="200" required
               value="<?= ViewHelper::e($vTitle) ?>">
    </div>

    <div class="col-md-3">
        <label class="form-label" for="task-type-<?= $uid ?>">Typ</label>
        <select name="task_type" id="task-type-<?= $uid ?>" class="form-select">
            <option value="aufgabe" <?= $vTaskType === 'aufgabe' ? 'selected' : '' ?>>Aufgabe</option>
            <option value="beigabe" <?= $vTaskType === 'beigabe' ? 'selected' : '' ?>>Beigabe</option>
        </select>
    </div>

    <div class="col-md-3">
        <label class="form-label" for="task-slot-<?= $uid ?>">Slot-Modus</label>
        <select name="slot_mode" id="task-slot-<?= $uid ?>" class="form-select">
            <option value="fix"      <?= $vSlotMode === 'fix'      ? 'selected' : '' ?>>Fixes Zeitfenster</option>
            <option value="variabel" <?= $vSlotMode === 'variabel' ? 'selected' : '' ?>>Variabel (User schlaegt vor)</option>
        </select>
    </div>

    <div class="col-md-12">
        <label class="form-label" for="task-desc-<?= $uid ?>">Beschreibung</label>
        <textarea name="description" id="task-desc-<?= $uid ?>" class="form-control" rows="2"><?= ViewHelper::e($vDescription) ?></textarea>
    </div>

    <div class="col-md-6">
        <label class="form-label" for="task-cat-<?= $uid ?>">Kategorie</label>
        <select name="category_id" id="task-cat-<?= $uid ?>" class="form-select">
            <option value="">- keine -</option>
            <?php foreach (($categories ?? []) as $c): ?>
                <option value="<?= (int) $c->getId() ?>"
                    <?= (int) $vCatId === (int) $c->getId() ? 'selected' : '' ?>>
                    <?= ViewHelper::e($c->getName()) ?>
                    <?php if ($c->isContribution()): ?> (Beigabe)<?php endif; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-md-3">
        <label class="form-label" for="task-start-<?= $uid ?>">Start (bei Slot=fix)</label>
        <input type="datetime-local" class="form-control" id="task-start-<?= $uid ?>" name="task_start_at"
               value="<?= ViewHelper::e($vStartAt) ?>">
    </div>

    <div class="col-md-3">
        <label class="form-label" for="task-end-<?= $uid ?>">Ende (bei Slot=fix)</label>
        <input type="datetime-local" class="form-control" id="task-end-<?= $uid ?>" name="task_end_at"
               value="<?= ViewHelper::e($vEndAt) ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label" for="task-cap-<?= $uid ?>">Kapazitaet</label>
        <select name="capacity_mode" id="task-cap-<?= $uid ?>" class="form-select">
            <option value="unbegrenzt" <?= $vCapacityMode === 'unbegrenzt' ? 'selected' : '' ?>>unbegrenzt</option>
            <option value="ziel"       <?= $vCapacityMode === 'ziel'       ? 'selected' : '' ?>>Ziel-Anzahl</option>
            <option value="maximum"    <?= $vCapacityMode === 'maximum'    ? 'selected' : '' ?>>Maximum (hart)</option>
        </select>
    </div>

    <div class="col-md-4">
        <label class="form-label" for="task-captarget-<?= $uid ?>">Anzahl (ziel/maximum)</label>
        <input type="number" class="form-control" id="task-captarget-<?= $uid ?>" name="capacity_target" min="1"
               value="<?= ViewHelper::e((string) $vCapacityTarget) ?>">
    </div>

    <div class="col-md-4">
        <label class="form-label" for="task-hours-<?= $uid ?>">Standard-Stunden</label>
        <input type="number" class="form-control" id="task-hours-<?= $uid ?>" name="hours_default" step="0.25" min="0"
               value="<?= ViewHelper::e((string) $vHoursDefault) ?>">
    </div>

    <div class="col-md-12">
        <button type="submit" class="btn btn-primary">
            <?php if ($isEdit): ?>
                <i class="bi bi-save"></i> Aenderungen speichern
            <?php else: ?>
                <i class="bi bi-plus-circle"></i> Aufgabe anlegen
            <?php endif; ?>
        </button>
    </div>
</form>
