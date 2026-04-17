<?php
/**
 * @var \App\Models\Event $event
 * @var \App\Models\EventTask[] $tasks
 * @var array $organizers
 */
use App\Helpers\ViewHelper;

$statusLabels = [
    'entwurf'         => ['class' => 'secondary', 'label' => 'Entwurf'],
    'veroeffentlicht' => ['class' => 'success',   'label' => 'Veroeffentlicht'],
    'abgeschlossen'   => ['class' => 'dark',      'label' => 'Abgeschlossen'],
    'abgesagt'        => ['class' => 'danger',    'label' => 'Abgesagt'],
];
$statusMeta = $statusLabels[$event->getStatus()] ?? ['class' => 'secondary', 'label' => $event->getStatus()];
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1"><?= ViewHelper::e($event->getTitle()) ?></h1>
        <span class="badge bg-<?= ViewHelper::e($statusMeta['class']) ?> fs-6">
            <?= ViewHelper::e($statusMeta['label']) ?>
        </span>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= ViewHelper::url('/admin/events/' . (int) $event->getId() . '/edit') ?>" class="btn btn-outline-primary">
            <i class="bi bi-pencil"></i> Bearbeiten
        </a>
        <?php if ($event->getStatus() === 'entwurf'): ?>
            <form method="POST" action="<?= ViewHelper::url('/admin/events/' . (int) $event->getId() . '/publish') ?>" class="d-inline">
                <?= ViewHelper::csrfField() ?>
                <button type="submit" class="btn btn-success"><i class="bi bi-send"></i> Veroeffentlichen</button>
            </form>
        <?php endif; ?>
        <?php
        // Abschliessen nur wenn veroeffentlicht UND Event-Ende vorbei
        $canComplete = $event->getStatus() === 'veroeffentlicht'
            && strtotime($event->getEndAt()) < time();
        ?>
        <?php if ($canComplete): ?>
            <form method="POST" action="<?= ViewHelper::url('/admin/events/' . (int) $event->getId() . '/complete') ?>" class="d-inline"
                  onsubmit="return confirm('Event abschliessen?&#10;&#10;Fuer alle bestaetigten Zusagen werden automatisch Helferstunden-Antraege zur Pruefung erzeugt.&#10;Dies kann nicht rueckgaengig gemacht werden.');">
                <?= ViewHelper::csrfField() ?>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check2-square"></i> Event abschliessen
                </button>
            </form>
        <?php endif; ?>
        <?php if ($event->getStatus() !== 'abgesagt' && $event->getStatus() !== 'abgeschlossen'): ?>
            <form method="POST" action="<?= ViewHelper::url('/admin/events/' . (int) $event->getId() . '/cancel') ?>" class="d-inline">
                <?= ViewHelper::csrfField() ?>
                <button type="submit" class="btn btn-outline-warning"
                        onclick="return confirm('Event wirklich absagen?')">
                    <i class="bi bi-x-circle"></i> Absagen
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h2 class="h6 text-muted">Eckdaten</h2>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Start</dt>
                    <dd class="col-sm-8"><?= ViewHelper::formatDateTime($event->getStartAt()) ?></dd>
                    <dt class="col-sm-4">Ende</dt>
                    <dd class="col-sm-8"><?= ViewHelper::formatDateTime($event->getEndAt()) ?></dd>
                    <dt class="col-sm-4">Ort</dt>
                    <dd class="col-sm-8"><?= ViewHelper::e($event->getLocation() ?? '-') ?></dd>
                    <dt class="col-sm-4">Storno bis</dt>
                    <dd class="col-sm-8"><?= (int) $event->getCancelDeadlineHours() ?>h vor Start</dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h2 class="h6 text-muted">Organisator(en)</h2>
                <?php if (empty($organizers)): ?>
                    <p class="text-danger mb-0">Keine Organisatoren zugewiesen!</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($organizers as $o): ?>
                            <li>
                                <i class="bi bi-person"></i>
                                <?= ViewHelper::e($o['vorname'] . ' ' . $o['nachname']) ?>
                                <small class="text-muted"><?= ViewHelper::e($o['email']) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($event->getDescription())): ?>
    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6 text-muted">Beschreibung</h2>
            <p class="mb-0"><?= nl2br(ViewHelper::e($event->getDescription())) ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0"><i class="bi bi-list-task"></i> Aufgaben und Beigaben</h2>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="collapse"
                data-bs-target="#newTaskForm" aria-expanded="false" aria-controls="newTaskForm">
            <i class="bi bi-plus"></i> Aufgabe hinzufuegen
        </button>
    </div>
    <div class="card-body">
        <div class="collapse mb-3" id="newTaskForm">
            <?php require __DIR__ . '/_task_form.php'; ?>
        </div>

        <?php if (empty($tasks)): ?>
            <p class="text-muted mb-0">Noch keine Aufgaben definiert.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Titel</th>
                            <th>Typ</th>
                            <th>Slot</th>
                            <th>Zeit</th>
                            <th>Kapazitaet</th>
                            <th>Stunden</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks as $t): ?>
                            <tr>
                                <td>
                                    <strong><?= ViewHelper::e($t->getTitle()) ?></strong>
                                    <?php if (!empty($t->getDescription())): ?>
                                        <br><small class="text-muted"><?= ViewHelper::e($t->getDescription()) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($t->isContribution()): ?>
                                        <span class="badge bg-info">Beigabe</span>
                                    <?php else: ?>
                                        <span class="badge bg-primary">Aufgabe</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= ViewHelper::e($t->getSlotMode()) ?></td>
                                <td>
                                    <?php if ($t->hasFixedSlot()): ?>
                                        <?= ViewHelper::formatDateTime($t->getStartAt()) ?>
                                        – <?= ViewHelper::formatDateTime($t->getEndAt()) ?>
                                    <?php else: ?>
                                        <em class="text-muted">frei</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= ViewHelper::e($t->getCapacityMode()) ?>
                                    <?php if ($t->getCapacityTarget() !== null): ?>
                                        (<?= (int) $t->getCapacityTarget() ?>)
                                    <?php endif; ?>
                                </td>
                                <td><?= ViewHelper::formatHours($t->getHoursDefault()) ?> h</td>
                                <td class="text-end">
                                    <form method="POST"
                                          action="<?= ViewHelper::url('/admin/events/' . (int) $event->getId() . '/tasks/' . (int) $t->getId() . '/delete') ?>"
                                          class="d-inline"
                                          onsubmit="return confirm('Aufgabe wirklich loeschen?');">
                                        <?= ViewHelper::csrfField() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Loeschen">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<form method="POST" action="<?= ViewHelper::url('/admin/events/' . (int) $event->getId() . '/delete') ?>"
      class="mt-4"
      onsubmit="return confirm('Event wirklich loeschen? Dies kann nicht rueckgaengig gemacht werden.');">
    <?= ViewHelper::csrfField() ?>
    <button type="submit" class="btn btn-outline-danger">
        <i class="bi bi-trash"></i> Event loeschen
    </button>
</form>
