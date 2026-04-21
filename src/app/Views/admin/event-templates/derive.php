<?php
/**
 * Event aus Template ableiten.
 *
 * @var \App\Models\EventTemplate $template
 * @var \App\Models\EventTemplateTask[] $tasks
 */
use App\Helpers\ViewHelper;
use App\Models\EventTask;
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0">
        <i class="bi bi-calendar-plus"></i> Event aus Template ableiten
    </h1>
    <a href="<?= ViewHelper::url('/admin/event-templates/' . (int) $template->getId()) ?>"
       class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurueck
    </a>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    Aus Template <strong><?= ViewHelper::e($template->getName()) ?>
    v<?= (int) $template->getVersion() ?></strong> werden
    <strong><?= count($tasks) ?></strong> Task-Vorlagen als Snapshot uebernommen.
    Das erzeugte Event startet im Status <em>Entwurf</em>.
</div>

<div class="card">
    <div class="card-body">
        <form method="POST"
              action="<?= ViewHelper::url('/admin/event-templates/' . (int) $template->getId() . '/derive') ?>"
              class="row g-3">
            <?= ViewHelper::csrfField() ?>

            <div class="col-md-6">
                <label class="form-label" for="evTitle">
                    Titel <span class="text-danger">*</span>
                </label>
                <input type="text" id="evTitle" class="form-control"
                       name="title" maxlength="200" required
                       value="<?= ViewHelper::e($template->getName()) ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label" for="evLocation">Ort</label>
                <input type="text" id="evLocation" class="form-control"
                       name="location" maxlength="500">
            </div>

            <div class="col-md-4">
                <label class="form-label" for="evStart">
                    Start <span class="text-danger">*</span>
                </label>
                <input type="datetime-local" id="evStart" class="form-control"
                       name="start_at" required>
            </div>

            <div class="col-md-4">
                <label class="form-label" for="evEnd">
                    Ende <span class="text-danger">*</span>
                </label>
                <input type="datetime-local" id="evEnd" class="form-control"
                       name="end_at" required>
            </div>

            <div class="col-md-4">
                <label class="form-label" for="evCancel">Storno-Vorlauf (Std.)</label>
                <input type="number" id="evCancel" class="form-control"
                       name="cancel_deadline_hours" min="0" max="720" value="24">
            </div>

            <div class="col-12">
                <label class="form-label" for="evDesc">Beschreibung</label>
                <textarea id="evDesc" class="form-control"
                          name="description" rows="3" maxlength="2000"></textarea>
            </div>

            <div class="col-12">
                <h2 class="h6 mt-3"><i class="bi bi-list-task"></i> Vorschau der Tasks</h2>
                <ul class="list-group list-group-flush">
                    <?php foreach ($tasks as $tt): ?>
                        <li class="list-group-item small">
                            <strong><?= ViewHelper::e($tt->getTitle()) ?></strong>
                            <?php if ($tt->getTaskType() === EventTask::TYPE_BEIGABE): ?>
                                <span class="badge bg-info">Beigabe</span>
                            <?php endif; ?>
                            <?php if ($tt->getDefaultOffsetMinutesStart() !== null): ?>
                                <span class="text-muted">
                                    | Offset
                                    <?= (int) $tt->getDefaultOffsetMinutesStart() ?> min
                                    <?= $tt->getDefaultOffsetMinutesEnd() !== null
                                        ? '&rarr; ' . (int) $tt->getDefaultOffsetMinutesEnd() . ' min' : '' ?>
                                </span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-calendar-plus"></i> Event erzeugen
                </button>
                <a href="<?= ViewHelper::url('/admin/event-templates/' . (int) $template->getId()) ?>"
                   class="btn btn-outline-secondary">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
