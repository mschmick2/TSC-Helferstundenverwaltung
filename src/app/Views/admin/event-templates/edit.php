<?php
/**
 * @var \App\Models\EventTemplate $template
 * @var \App\Models\EventTemplateTask[] $tasks
 * @var \App\Models\Category[] $categories
 * @var bool $hasDerivedEvents
 */
use App\Helpers\ViewHelper;
use App\Models\EventTask;
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1">
            <i class="bi bi-pencil-square"></i>
            <?= ViewHelper::e($template->getName()) ?>
            <small class="text-muted">v<?= (int) $template->getVersion() ?></small>
        </h1>
        <span class="badge bg-success">Aktuelle Version</span>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= ViewHelper::url('/admin/event-templates/' . (int) $template->getId()) ?>"
           class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Zurueck
        </a>
        <a href="<?= ViewHelper::url('/admin/event-templates/' . (int) $template->getId() . '/derive') ?>"
           class="btn btn-primary <?= empty($tasks) ? 'disabled' : '' ?>"
           <?= empty($tasks) ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
            <i class="bi bi-calendar-plus"></i> Event ableiten
        </a>
    </div>
</div>

<?php if ($hasDerivedEvents): ?>
    <div class="alert alert-warning" role="alert">
        <i class="bi bi-lock-fill"></i>
        <strong>Template gesperrt:</strong> aus dieser Version wurden bereits Events abgeleitet.
        Aenderungen nur ueber <strong>"Als neue Version speichern"</strong> moeglich.
    </div>
<?php endif; ?>

<?php if (!empty($tasks)): ?>
    <!-- Save as new Version -->
    <div class="card mb-3">
        <div class="card-header">
            <h2 class="h5 mb-0"><i class="bi bi-clock-history"></i> Als neue Version speichern</h2>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                Erzeugt eine neue Version (v<?= (int) $template->getVersion() + 1 ?>) inklusive aller aktuellen Tasks.
                Die aktuelle Version wird auf "nicht aktuell" gesetzt.
            </p>
            <form method="POST"
                  action="<?= ViewHelper::url('/admin/event-templates/' . (int) $template->getId() . '/save-as-new-version') ?>"
                  class="row g-2">
                <?= ViewHelper::csrfField() ?>
                <div class="col-md-5">
                    <label class="form-label" for="newVersionName">Name <span class="text-danger">*</span></label>
                    <input type="text" id="newVersionName" class="form-control"
                           name="name" maxlength="200" required
                           value="<?= ViewHelper::e($template->getName()) ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="newVersionDesc">Beschreibung</label>
                    <input type="text" id="newVersionDesc" class="form-control"
                           name="description" maxlength="500"
                           value="<?= ViewHelper::e($template->getDescription() ?? '') ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="bi bi-save"></i> v<?= (int) $template->getVersion() + 1 ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- Task-Liste mit Inline-Edit -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0"><i class="bi bi-list-task"></i> Task-Vorlagen (<?= count($tasks) ?>)</h2>
        <?php if (!$hasDerivedEvents): ?>
            <button type="button" class="btn btn-sm btn-primary"
                    data-bs-toggle="collapse" data-bs-target="#newTaskForm">
                <i class="bi bi-plus-circle"></i> Neue Task
            </button>
        <?php endif; ?>
    </div>

    <?php if (!$hasDerivedEvents): ?>
    <div class="collapse" id="newTaskForm">
        <div class="card-body border-bottom bg-light">
            <form method="POST"
                  action="<?= ViewHelper::url('/admin/event-templates/' . (int) $template->getId() . '/tasks') ?>"
                  class="row g-2">
                <?= ViewHelper::csrfField() ?>
                <?php require __DIR__ . '/_task_form_fields.php'; ?>
                <div class="col-12">
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-plus-circle"></i> Task hinzufuegen
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card-body">
        <?php if (empty($tasks)): ?>
            <p class="text-muted mb-0">
                <i class="bi bi-inbox"></i> Noch keine Tasks. Erste Task ueber "Neue Task" hinzufuegen.
            </p>
        <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($tasks as $tt): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div class="flex-grow-1">
                                <strong><?= ViewHelper::e($tt->getTitle()) ?></strong>
                                <?php if ($tt->getTaskType() === EventTask::TYPE_BEIGABE): ?>
                                    <span class="badge bg-info">Beigabe</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Aufgabe</span>
                                <?php endif; ?>
                                <span class="badge bg-light text-dark">
                                    Slot: <?= ViewHelper::e($tt->getSlotMode()) ?>
                                </span>
                                <span class="badge bg-light text-dark">
                                    Kapazitaet: <?= ViewHelper::e($tt->getCapacityMode()) ?>
                                    <?= $tt->getCapacityTarget() !== null ? '(' . (int) $tt->getCapacityTarget() . ')' : '' ?>
                                </span>
                                <span class="badge bg-light text-dark">
                                    <?= number_format($tt->getHoursDefault(), 2, ',', '.') ?> h
                                </span>
                                <?php if ($tt->getDefaultOffsetMinutesStart() !== null
                                        || $tt->getDefaultOffsetMinutesEnd() !== null): ?>
                                    <span class="badge bg-light text-dark" title="Offset zum Event-Start in Minuten">
                                        <i class="bi bi-clock"></i>
                                        <?= (int) ($tt->getDefaultOffsetMinutesStart() ?? 0) ?>
                                        &rarr;
                                        <?= (int) ($tt->getDefaultOffsetMinutesEnd() ?? 0) ?> min
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($tt->getDescription())): ?>
                                    <br><small class="text-muted"><?= ViewHelper::e($tt->getDescription()) ?></small>
                                <?php endif; ?>
                            </div>

                            <?php if (!$hasDerivedEvents): ?>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-secondary"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#editTask<?= (int) $tt->getId() ?>"
                                            aria-label="Task bearbeiten">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST"
                                          action="<?= ViewHelper::url(
                                              '/admin/event-templates/' . (int) $template->getId()
                                              . '/tasks/' . (int) $tt->getId() . '/delete'
                                          ) ?>"
                                          class="d-inline"
                                          onsubmit="return confirm('Task wirklich loeschen?');">
                                        <?= ViewHelper::csrfField() ?>
                                        <button type="submit" class="btn btn-outline-danger"
                                                aria-label="Task loeschen">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!$hasDerivedEvents): ?>
                            <div class="collapse mt-2" id="editTask<?= (int) $tt->getId() ?>">
                                <form method="POST"
                                      action="<?= ViewHelper::url(
                                          '/admin/event-templates/' . (int) $template->getId()
                                          . '/tasks/' . (int) $tt->getId() . '/update'
                                      ) ?>"
                                      class="row g-2 p-2 bg-light rounded">
                                    <?= ViewHelper::csrfField() ?>
                                    <?php
                                    $task = $tt;
                                    require __DIR__ . '/_task_form_fields.php';
                                    ?>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <i class="bi bi-save"></i> Speichern
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
