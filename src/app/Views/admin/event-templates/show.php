<?php
/**
 * @var \App\Models\EventTemplate $template
 * @var \App\Models\EventTemplateTask[] $tasks
 * @var \App\Models\EventTemplate[] $versions
 */
use App\Helpers\ViewHelper;
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1">
            <i class="bi bi-card-list"></i> <?= ViewHelper::e($template->getName()) ?>
            <small class="text-muted">v<?= (int) $template->getVersion() ?></small>
        </h1>
        <?php if ($template->isCurrent()): ?>
            <span class="badge bg-success">Aktuelle Version</span>
        <?php else: ?>
            <span class="badge bg-secondary">Alte Version</span>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= ViewHelper::url('/admin/event-templates') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Zurueck
        </a>
        <?php if ($template->isCurrent()): ?>
            <a href="<?= ViewHelper::url('/admin/event-templates/' . (int) $template->getId() . '/edit') ?>"
               class="btn btn-outline-primary">
                <i class="bi bi-pencil"></i> Bearbeiten
            </a>
            <?php if (!empty($tasks)): ?>
                <a href="<?= ViewHelper::url('/admin/event-templates/' . (int) $template->getId() . '/derive') ?>"
                   class="btn btn-primary">
                    <i class="bi bi-calendar-plus"></i> Event ableiten
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($hasDerivedEvents ?? false)): ?>
    <div class="alert alert-secondary small">
        <i class="bi bi-info-circle"></i>
        Aus dieser Template-Version wurden bereits Events abgeleitet. Bearbeitung nur via "Als neue Version speichern".
    </div>
<?php endif; ?>

<?php if (!empty($template->getDescription())): ?>
    <div class="card mb-3">
        <div class="card-body">
            <p class="mb-0"><?= nl2br(ViewHelper::e($template->getDescription())) ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header">
        <h2 class="h5 mb-0"><i class="bi bi-list-task"></i> Task-Vorlagen</h2>
    </div>
    <div class="card-body">
        <?php if (empty($tasks)): ?>
            <p class="text-muted mb-0">
                Noch keine Task-Vorlagen definiert.
                <?php if ($template->isCurrent()): ?>
                    <a href="<?= ViewHelper::url('/admin/event-templates/' . (int) $template->getId() . '/edit') ?>">
                        Jetzt Tasks hinzufuegen.
                    </a>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($tasks as $tt): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?= ViewHelper::e($tt->getTitle()) ?></strong>
                            <?php if ($tt->getTaskType() === 'beigabe'): ?>
                                <span class="badge bg-info">Beigabe</span>
                            <?php endif; ?>
                            <?php if (!empty($tt->getDescription())): ?>
                                <br><small class="text-muted"><?= ViewHelper::e($tt->getDescription()) ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted small">
                            <?= ViewHelper::e($tt->getCapacityMode()) ?>
                            | <?= ViewHelper::formatHours($tt->getHoursDefault()) ?> h
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php if (count($versions) > 1): ?>
    <div class="card mb-3">
        <div class="card-header">
            <h2 class="h5 mb-0"><i class="bi bi-clock-history"></i> Versionen</h2>
        </div>
        <ul class="list-group list-group-flush">
            <?php foreach ($versions as $v): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <a href="<?= ViewHelper::url('/admin/event-templates/' . (int) $v->getId()) ?>">
                        <?= ViewHelper::e($v->getName()) ?> v<?= (int) $v->getVersion() ?>
                    </a>
                    <span>
                        <?php if ($v->isCurrent()): ?><span class="badge bg-success">Aktuell</span><?php endif; ?>
                        <small class="text-muted ms-2"><?= ViewHelper::formatDate($v->getCreatedAt()) ?></small>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
