<?php
/**
 * @var \App\Models\Event $event
 * @var \App\Models\EventTask[] $tasks
 * @var array<int, array{current_count:int, user_has_assignment:bool}> $taskMeta
 */
use App\Helpers\ViewHelper;
use App\Models\EventTask;
?>

<div class="mb-4">
    <h1 class="h3"><?= ViewHelper::e($event->getTitle()) ?></h1>
    <p class="text-muted mb-1">
        <i class="bi bi-clock"></i> <?= ViewHelper::formatDateTime($event->getStartAt()) ?>
        &ndash; <?= ViewHelper::formatDateTime($event->getEndAt()) ?>
    </p>
    <?php if ($event->getLocation() !== null && $event->getLocation() !== ''): ?>
        <p class="text-muted mb-1">
            <i class="bi bi-geo-alt"></i> <?= ViewHelper::e($event->getLocation()) ?>
        </p>
    <?php endif; ?>
    <?php if ($event->getDescription() !== null && $event->getDescription() !== ''): ?>
        <p class="mt-3"><?= nl2br(ViewHelper::e($event->getDescription())) ?></p>
    <?php endif; ?>
</div>

<?php
// Modul 6 I7b2 Phase 1: Controller liefert bei Flag=1 und vorhandener
// Baumstruktur bereits $treeEditorEnabled / $hasTreeStructure / $treeData.
// Das rekursive Accordion-Partial folgt in Phase 2 — bis dahin fallen wir
// auf die bestehende flache Karten-Liste zurueck und zeigen in dev/test
// einen Hinweis, damit Tester sehen, dass der Daten-Flow aus dem Controller
// korrekt greift.
$showAccordion = !empty($treeEditorEnabled) && !empty($hasTreeStructure);
?>

<h2 class="h5 mb-3"><i class="bi bi-list-task"></i> Aufgaben und Beigaben</h2>

<?php if ($showAccordion): ?>
    <div class="alert alert-secondary d-flex align-items-center gap-2 mb-3" role="alert">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span class="small">
            Hinweis: Hierarchische Baumansicht wird in I7b2 Phase 2 nachgeliefert.
            Aktuell zeigen wir die flache Liste.
        </span>
    </div>
<?php endif; ?>

<?php if (empty($tasks)): ?>
    <p class="text-muted">Noch keine Aufgaben definiert.</p>
<?php else: ?>
    <div class="row g-2">
    <?php foreach ($tasks as $t):
        $meta = $taskMeta[$t->getId()] ?? ['current_count' => 0, 'user_has_assignment' => false];
        $isFull = $t->getCapacityMode() === EventTask::CAP_MAXIMUM
            && $t->getCapacityTarget() !== null
            && $meta['current_count'] >= (int) $t->getCapacityTarget();
    ?>
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <h3 class="h6 mb-0">
                            <?= ViewHelper::e($t->getTitle()) ?>
                            <?php if ($t->isContribution()): ?>
                                <span class="badge bg-info">Beigabe</span>
                            <?php else: ?>
                                <span class="badge bg-primary">Aufgabe</span>
                            <?php endif; ?>
                        </h3>
                        <small class="text-muted">
                            <?= ViewHelper::formatHours($t->getHoursDefault()) ?> h
                        </small>
                    </div>

                    <?php if (!empty($t->getDescription())): ?>
                        <p class="mb-2 mt-2 small text-muted"><?= ViewHelper::e($t->getDescription()) ?></p>
                    <?php endif; ?>

                    <?php if ($t->hasFixedSlot()): ?>
                        <p class="mb-2 small">
                            <i class="bi bi-clock"></i>
                            <?= ViewHelper::formatDateTime($t->getStartAt()) ?>
                            &ndash; <?= ViewHelper::formatDateTime($t->getEndAt()) ?>
                        </p>
                    <?php else: ?>
                        <p class="mb-2 small"><i class="bi bi-sliders"></i> Freies Zeitfenster</p>
                    <?php endif; ?>

                    <p class="mb-2 small text-muted">
                        <?php if ($t->getCapacityTarget() !== null): ?>
                            <?= (int) $meta['current_count'] ?>/<?= (int) $t->getCapacityTarget() ?> Helfer
                        <?php else: ?>
                            Beliebig viele Helfer
                        <?php endif; ?>
                    </p>

                    <?php if ($meta['user_has_assignment']): ?>
                        <button class="btn btn-success btn-sm w-100" disabled>
                            <i class="bi bi-check-circle"></i> Bereits zugesagt
                        </button>
                    <?php elseif ($isFull): ?>
                        <button class="btn btn-secondary btn-sm w-100" disabled>
                            <i class="bi bi-slash-circle"></i> Ausgebucht
                        </button>
                    <?php else: ?>
                        <?php require __DIR__ . '/_assign_form.php'; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
