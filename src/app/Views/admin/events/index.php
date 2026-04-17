<?php
/**
 * @var \App\Models\Event[] $events
 * @var ?string $statusFilter
 */
use App\Helpers\ViewHelper;

$statusLabels = [
    'entwurf'         => ['class' => 'secondary', 'label' => 'Entwurf'],
    'veroeffentlicht' => ['class' => 'success',   'label' => 'Veroeffentlicht'],
    'abgeschlossen'   => ['class' => 'dark',      'label' => 'Abgeschlossen'],
    'abgesagt'        => ['class' => 'danger',    'label' => 'Abgesagt'],
];
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-calendar-event"></i> Events</h1>
    <div>
        <a href="<?= ViewHelper::url('/admin/event-templates') ?>" class="btn btn-outline-secondary me-2">
            <i class="bi bi-card-list"></i> Templates
        </a>
        <a href="<?= ViewHelper::url('/admin/events/create') ?>" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Neues Event
        </a>
    </div>
</div>

<form method="get" class="mb-3">
    <div class="input-group" style="max-width: 420px;">
        <label class="input-group-text" for="statusFilter">Status</label>
        <select name="status" id="statusFilter" class="form-select" onchange="this.form.submit()">
            <option value="">Alle</option>
            <?php foreach ($statusLabels as $key => $meta): ?>
                <option value="<?= ViewHelper::e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>>
                    <?= ViewHelper::e($meta['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<?php if (empty($events)): ?>
    <div class="alert alert-info">
        <i class="bi bi-inbox"></i> Keine Events vorhanden. <a href="<?= ViewHelper::url('/admin/events/create') ?>">Jetzt anlegen.</a>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Titel</th>
                    <th>Start</th>
                    <th class="d-none d-md-table-cell">Ende</th>
                    <th class="d-none d-lg-table-cell">Ort</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $e): ?>
                    <tr>
                        <td>
                            <a href="<?= ViewHelper::url('/admin/events/' . (int) $e->getId()) ?>">
                                <?= ViewHelper::e($e->getTitle()) ?>
                            </a>
                        </td>
                        <td><?= ViewHelper::formatDateTime($e->getStartAt()) ?></td>
                        <td class="d-none d-md-table-cell"><?= ViewHelper::formatDateTime($e->getEndAt()) ?></td>
                        <td class="d-none d-lg-table-cell"><?= ViewHelper::e($e->getLocation() ?? '-') ?></td>
                        <td>
                            <?php $meta = $statusLabels[$e->getStatus()] ?? ['class' => 'secondary', 'label' => $e->getStatus()]; ?>
                            <span class="badge bg-<?= ViewHelper::e($meta['class']) ?>">
                                <?= ViewHelper::e($meta['label']) ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <a href="<?= ViewHelper::url('/admin/events/' . (int) $e->getId() . '/edit') ?>" class="btn btn-sm btn-outline-primary" aria-label="Bearbeiten">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
