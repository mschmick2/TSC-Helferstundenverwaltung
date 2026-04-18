<?php
/**
 * Form-Fields-Partial fuer Template-Task Create/Edit.
 *
 * @var \App\Models\Category[] $categories
 * @var \App\Models\EventTemplateTask|null $task  optional (edit-Modus)
 */
use App\Helpers\ViewHelper;
use App\Models\EventTask;

$t = $task ?? null;
$uniqPrefix = 'tf_' . ($t?->getId() ?? 'new') . '_';
?>

<div class="col-md-6">
    <label class="form-label" for="<?= $uniqPrefix ?>title">
        Titel <span class="text-danger">*</span>
    </label>
    <input type="text" id="<?= $uniqPrefix ?>title" class="form-control"
           name="title" maxlength="200" required
           value="<?= ViewHelper::e($t?->getTitle() ?? '') ?>">
</div>

<div class="col-md-3">
    <label class="form-label" for="<?= $uniqPrefix ?>type">Typ</label>
    <select id="<?= $uniqPrefix ?>type" class="form-select" name="task_type">
        <option value="<?= EventTask::TYPE_AUFGABE ?>"
            <?= ($t?->getTaskType() ?? EventTask::TYPE_AUFGABE) === EventTask::TYPE_AUFGABE ? 'selected' : '' ?>>
            Aufgabe
        </option>
        <option value="<?= EventTask::TYPE_BEIGABE ?>"
            <?= $t?->getTaskType() === EventTask::TYPE_BEIGABE ? 'selected' : '' ?>>
            Beigabe
        </option>
    </select>
</div>

<div class="col-md-3">
    <label class="form-label" for="<?= $uniqPrefix ?>slot">Slot-Modus</label>
    <select id="<?= $uniqPrefix ?>slot" class="form-select" name="slot_mode">
        <option value="<?= EventTask::SLOT_FIX ?>"
            <?= ($t?->getSlotMode() ?? EventTask::SLOT_FIX) === EventTask::SLOT_FIX ? 'selected' : '' ?>>
            Fix (mit Zeit)
        </option>
        <option value="<?= EventTask::SLOT_VARIABEL ?>"
            <?= $t?->getSlotMode() === EventTask::SLOT_VARIABEL ? 'selected' : '' ?>>
            Variabel
        </option>
    </select>
</div>

<div class="col-md-6">
    <label class="form-label" for="<?= $uniqPrefix ?>cat">Kategorie</label>
    <select id="<?= $uniqPrefix ?>cat" class="form-select" name="category_id">
        <option value="">-- keine --</option>
        <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c->getId() ?>"
                <?= $t?->getCategoryId() === $c->getId() ? 'selected' : '' ?>>
                <?= ViewHelper::e($c->getName()) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<div class="col-md-3">
    <label class="form-label" for="<?= $uniqPrefix ?>capm">Kapazitaet</label>
    <select id="<?= $uniqPrefix ?>capm" class="form-select" name="capacity_mode">
        <option value="<?= EventTask::CAP_UNBEGRENZT ?>"
            <?= ($t?->getCapacityMode() ?? EventTask::CAP_UNBEGRENZT) === EventTask::CAP_UNBEGRENZT ? 'selected' : '' ?>>
            Unbegrenzt
        </option>
        <option value="<?= EventTask::CAP_ZIEL ?>"
            <?= $t?->getCapacityMode() === EventTask::CAP_ZIEL ? 'selected' : '' ?>>
            Ziel
        </option>
        <option value="<?= EventTask::CAP_MAXIMUM ?>"
            <?= $t?->getCapacityMode() === EventTask::CAP_MAXIMUM ? 'selected' : '' ?>>
            Maximum
        </option>
    </select>
</div>

<div class="col-md-3">
    <label class="form-label" for="<?= $uniqPrefix ?>captgt">Kapazitaet-Wert</label>
    <input type="number" id="<?= $uniqPrefix ?>captgt" class="form-control"
           name="capacity_target" min="0" max="1000"
           value="<?= $t?->getCapacityTarget() ?? '' ?>"
           title="Pflicht bei Kapazitaet ziel/maximum">
</div>

<div class="col-md-3">
    <label class="form-label" for="<?= $uniqPrefix ?>hours">Std-Default</label>
    <input type="number" id="<?= $uniqPrefix ?>hours" class="form-control"
           name="hours_default" step="0.25" min="0" max="24"
           value="<?= number_format($t?->getHoursDefault() ?? 0, 2, '.', '') ?>">
</div>

<div class="col-md-3">
    <label class="form-label" for="<?= $uniqPrefix ?>sort">Sortier-Nr</label>
    <input type="number" id="<?= $uniqPrefix ?>sort" class="form-control"
           name="sort_order" min="0"
           value="<?= (int) ($t?->getSortOrder() ?? 0) ?>">
</div>

<div class="col-md-3">
    <label class="form-label" for="<?= $uniqPrefix ?>offs">Offset Start (Min.)</label>
    <input type="number" id="<?= $uniqPrefix ?>offs" class="form-control"
           name="default_offset_minutes_start"
           value="<?= $t?->getDefaultOffsetMinutesStart() ?? '' ?>"
           title="Minuten relativ zum Event-Start (negativ = vor Start)">
</div>

<div class="col-md-3">
    <label class="form-label" for="<?= $uniqPrefix ?>offe">Offset Ende (Min.)</label>
    <input type="number" id="<?= $uniqPrefix ?>offe" class="form-control"
           name="default_offset_minutes_end"
           value="<?= $t?->getDefaultOffsetMinutesEnd() ?? '' ?>">
</div>

<div class="col-12">
    <label class="form-label" for="<?= $uniqPrefix ?>desc">Beschreibung</label>
    <textarea id="<?= $uniqPrefix ?>desc" class="form-control"
              name="description" rows="2"
              maxlength="2000"><?= ViewHelper::e($t?->getDescription() ?? '') ?></textarea>
</div>
