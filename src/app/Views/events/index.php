<?php
/**
 * @var \App\Models\Event[] $events
 */
use App\Helpers\ViewHelper;
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-calendar-event"></i> Events</h1>
    <div class="btn-group">
        <a href="<?= ViewHelper::url('/events/calendar') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-calendar3"></i> Kalender
        </a>
        <a href="<?= ViewHelper::url('/my-events') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-person-check"></i> Meine Zusagen
        </a>
    </div>
</div>

<?php if (empty($events)): ?>
    <div class="alert alert-info">
        <i class="bi bi-calendar-x"></i> Aktuell keine Events zum Mithelfen.
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($events as $e): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h2 class="h5 card-title">
                            <a href="<?= ViewHelper::url('/events/' . (int) $e->getId()) ?>">
                                <?= ViewHelper::e($e->getTitle()) ?>
                            </a>
                        </h2>
                        <p class="text-muted mb-2">
                            <i class="bi bi-clock"></i> <?= ViewHelper::formatDateTime($e->getStartAt()) ?>
                            &ndash; <?= ViewHelper::formatDateTime($e->getEndAt()) ?>
                        </p>
                        <?php if ($e->getLocation() !== null && $e->getLocation() !== ''): ?>
                            <p class="text-muted mb-2">
                                <i class="bi bi-geo-alt"></i> <?= ViewHelper::e($e->getLocation()) ?>
                            </p>
                        <?php endif; ?>
                        <?php if ($e->getDescription() !== null && $e->getDescription() !== ''): ?>
                            <p class="card-text">
                                <?= ViewHelper::e(mb_substr($e->getDescription(), 0, 200)) ?>
                                <?= mb_strlen($e->getDescription()) > 200 ? '&hellip;' : '' ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer bg-white">
                        <a href="<?= ViewHelper::url('/events/' . (int) $e->getId()) ?>"
                           class="btn btn-primary btn-sm w-100">
                            Aufgaben ansehen
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
