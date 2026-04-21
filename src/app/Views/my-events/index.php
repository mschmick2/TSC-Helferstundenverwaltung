<?php
/**
 * @var \App\Models\EventTaskAssignment[] $assignments
 * @var array<int, array{task: ?\App\Models\EventTask, event: ?\App\Models\Event}> $context
 * @var \App\Models\User[] $replacementCandidates
 */
use App\Helpers\ViewHelper;
use App\Models\EventTaskAssignment;

$replacementCandidates = $replacementCandidates ?? [];

$statusLabel = [
    EventTaskAssignment::STATUS_VORGESCHLAGEN    => ['class' => 'warning', 'label' => 'Zeitfenster vorgeschlagen'],
    EventTaskAssignment::STATUS_BESTAETIGT       => ['class' => 'success', 'label' => 'Bestaetigt'],
    EventTaskAssignment::STATUS_STORNO_ANGEFRAGT => ['class' => 'warning', 'label' => 'Storno angefragt'],
    EventTaskAssignment::STATUS_STORNIERT        => ['class' => 'secondary', 'label' => 'Storniert'],
    EventTaskAssignment::STATUS_ABGESCHLOSSEN    => ['class' => 'dark', 'label' => 'Abgeschlossen'],
];
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-person-check"></i> Meine Zusagen</h1>
    <div class="btn-group">
        <a href="<?= ViewHelper::url('/my-events/calendar') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-calendar-heart"></i> Kalender
        </a>
        <a href="<?= ViewHelper::url('/my-events/ical') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-link-45deg"></i> iCal-Abo
        </a>
    </div>
</div>

<?php if (empty($assignments)): ?>
    <div class="alert alert-info">
        Noch keine Event-Zusagen.
        <a href="<?= ViewHelper::url('/events') ?>">Events ansehen</a>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Aufgabe</th>
                    <th class="d-none d-md-table-cell">Datum</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assignments as $a):
                    $ctx = $context[$a->getId()] ?? ['task' => null, 'event' => null];
                    $task = $ctx['task'];
                    $event = $ctx['event'];
                    $meta = $statusLabel[$a->getStatus()] ?? ['class' => 'secondary', 'label' => $a->getStatus()];
                ?>
                    <tr>
                        <td>
                            <?php if ($event !== null): ?>
                                <a href="<?= ViewHelper::url('/events/' . (int) $event->getId()) ?>">
                                    <?= ViewHelper::e($event->getTitle()) ?>
                                </a>
                            <?php else: ?>
                                <em class="text-muted">geloescht</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $task !== null ? ViewHelper::e($task->getTitle()) : '-' ?>
                        </td>
                        <td class="d-none d-md-table-cell">
                            <?= $event !== null ? ViewHelper::formatDateTime($event->getStartAt()) : '-' ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= ViewHelper::e($meta['class']) ?>">
                                <?= ViewHelper::e($meta['label']) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <?php if ($a->getStatus() === EventTaskAssignment::STATUS_VORGESCHLAGEN): ?>
                                <form method="POST"
                                      action="<?= ViewHelper::url('/my-events/assignments/' . (int) $a->getId() . '/withdraw') ?>"
                                      class="d-inline"
                                      onsubmit="return confirm('Zusage wirklich zurueckziehen?');">
                                    <?= ViewHelper::csrfField() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-x"></i> Zurueckziehen
                                    </button>
                                </form>
                            <?php elseif ($a->getStatus() === EventTaskAssignment::STATUS_BESTAETIGT): ?>
                                <button type="button" class="btn btn-sm btn-outline-warning"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#cancelForm<?= (int) $a->getId() ?>">
                                    <i class="bi bi-x-circle"></i> Storno
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($a->getStatus() === EventTaskAssignment::STATUS_BESTAETIGT): ?>
                        <tr class="collapse" id="cancelForm<?= (int) $a->getId() ?>">
                            <td colspan="5" class="bg-light">
                                <form method="POST"
                                      action="<?= ViewHelper::url('/my-events/assignments/' . (int) $a->getId() . '/cancel') ?>"
                                      class="row g-2">
                                    <?= ViewHelper::csrfField() ?>
                                    <div class="col-md-6">
                                        <label class="form-label small">Begruendung (optional)</label>
                                        <input type="text" name="reason" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">Ersatz vorschlagen (optional)</label>
                                        <select name="replacement_user_id" class="form-select form-select-sm">
                                            <option value="">-- kein Vorschlag --</option>
                                            <?php foreach ($replacementCandidates as $cand): ?>
                                                <option value="<?= (int) $cand->getId() ?>">
                                                    <?= ViewHelper::e($cand->getNachname() . ', ' . $cand->getVorname()) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-12">
                                        <button type="submit" class="btn btn-warning btn-sm">
                                            Storno-Anfrage senden
                                        </button>
                                        <small class="text-muted">Organisator muss bestaetigen.</small>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
