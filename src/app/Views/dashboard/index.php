<?php
/**
 * Dashboard
 *
 * Variablen: $user (App\Models\User), $settings
 */

use App\Helpers\ViewHelper;
?>

<div id="dashboard-page">
<div class="row">
    <div class="col-12">
        <h2 class="mb-4">
            <i class="bi bi-house"></i> Dashboard
        </h2>
        <p class="text-muted">Willkommen, <?= ViewHelper::e($user->getVollname()) ?>!</p>
    </div>
</div>

<!-- Schnellaktionen -->
<div class="row g-4 mb-4">
    <?php if ($user->hasRole('mitglied') || $user->hasRole('erfasser') || $user->isAdmin()): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card h-100 border-primary">
            <div class="card-body text-center">
                <i class="bi bi-plus-circle text-primary" style="font-size: 2.5rem;"></i>
                <h5 class="card-title mt-3">Stunden erfassen</h5>
                <p class="card-text text-muted small">Neue Arbeitsstunden eintragen.</p>
                <a href="<?= ViewHelper::url('/entries/create') ?>" class="btn btn-primary">
                    <i class="bi bi-plus"></i> Neuer Eintrag
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body text-center">
                <i class="bi bi-list-check text-success" style="font-size: 2.5rem;"></i>
                <h5 class="card-title mt-3">Meine Einträge</h5>
                <p class="card-text text-muted small">Übersicht Ihrer Arbeitsstunden.</p>
                <a href="<?= ViewHelper::url('/entries') ?>" class="btn btn-outline-success">
                    <i class="bi bi-list"></i> Anzeigen
                </a>
            </div>
        </div>
    </div>

    <?php if ($user->canReview()): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card h-100 border-warning">
            <div class="card-body text-center">
                <i class="bi bi-clipboard-check text-warning" style="font-size: 2.5rem;"></i>
                <h5 class="card-title mt-3">
                    Anträge prüfen
                    <?php if ($pendingReviewCount > 0): ?>
                        <span class="badge bg-warning text-dark"><?= (int) $pendingReviewCount ?></span>
                    <?php endif; ?>
                </h5>
                <p class="card-text text-muted small">
                    <?php if ($pendingReviewCount > 0): ?>
                        <?= (int) $pendingReviewCount ?> <?= $pendingReviewCount === 1 ? 'Antrag wartet' : 'Anträge warten' ?> auf Prüfung.
                    <?php else: ?>
                        Keine offenen Anträge.
                    <?php endif; ?>
                </p>
                <a href="<?= ViewHelper::url('/review') ?>" class="btn btn-outline-warning">
                    <i class="bi bi-clipboard"></i> Prüfliste
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($user->isAdmin()): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body text-center">
                <i class="bi bi-people text-info" style="font-size: 2.5rem;"></i>
                <h5 class="card-title mt-3">Mitglieder</h5>
                <p class="card-text text-muted small">Mitglieder verwalten und importieren.</p>
                <a href="<?= ViewHelper::url('/admin/users') ?>" class="btn btn-outline-info">
                    <i class="bi bi-people"></i> Verwalten
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Ungelesene Dialog-Nachrichten -->
<?php if (!empty($unreadDialogs)): ?>
<div class="row mb-4" id="dashboard-notifications">
    <div class="col-12">
        <div class="card border-warning">
            <div class="card-header bg-warning bg-opacity-10">
                <h6 class="mb-0">
                    <i class="bi bi-chat-dots-fill text-warning"></i>
                    Neue Dialog-Nachrichten
                    <span class="badge bg-warning text-dark ms-1"><?= count($unreadDialogs) ?></span>
                </h6>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($unreadDialogs as $dialog): ?>
                    <a href="<?= ViewHelper::url('/entries/' . (int) $dialog['entry_id']) ?>"
                       class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?= ViewHelper::e($dialog['entry_number']) ?></strong>
                            <span class="text-muted mx-1">&ndash;</span>
                            <?= ViewHelper::e($dialog['entry_owner_name']) ?>
                            <span class="badge <?= \App\Models\WorkEntry::STATUS_BADGES[$dialog['status']] ?? 'bg-secondary' ?> ms-2">
                                <?= ViewHelper::e(\App\Models\WorkEntry::STATUS_LABELS[$dialog['status']] ?? $dialog['status']) ?>
                            </span>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-warning text-dark">
                                <?= (int) $dialog['unread_count'] ?> neue
                            </span>
                            <small class="text-muted ms-2"><?= ViewHelper::formatDateTime($dialog['latest_message_at']) ?></small>
                        </div>
                    </a>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Soll/Ist-Stunden (wenn aktiviert) -->
<?php if (!empty($targetHoursEnabled) && $targetComparison !== null): ?>
    <?php
    $tc = $targetComparison;
    $tcTarget = (float) $tc['target'];
    $tcActual = (float) $tc['actual'];
    $tcRemaining = (float) $tc['remaining'];
    $tcPercentage = (float) $tc['percentage'];
    $tcExempt = (bool) $tc['is_exempt'];
    $tcFulfilled = $tcActual >= $tcTarget;
    ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="card <?= $tcExempt ? 'border-secondary' : ($tcFulfilled ? 'border-success' : 'border-warning') ?>">
                <div class="card-body">
                    <h6 class="card-title">
                        <i class="bi bi-bullseye"></i> Soll-Stunden <?= date('Y') ?>
                    </h6>
                    <?php if ($tcExempt): ?>
                        <p class="mb-0 text-muted">Sie sind von den Soll-Stunden befreit.</p>
                    <?php else: ?>
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <div class="progress mb-2" style="height: 25px;">
                                    <div class="progress-bar <?= $tcFulfilled ? 'bg-success' : ($tcPercentage >= 50 ? 'bg-warning' : 'bg-danger') ?>"
                                         style="width: <?= round($tcPercentage) ?>%;">
                                        <?= round($tcPercentage, 1) ?>%
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <span class="fw-bold"><?= number_format($tcActual, 1, ',', '.') ?></span>
                                / <?= number_format($tcTarget, 1, ',', '.') ?> Std.
                                <?php if ($tcFulfilled): ?>
                                    <span class="badge bg-success ms-1">Erfüllt</span>
                                <?php else: ?>
                                    <span class="text-muted small d-block">Noch <?= number_format($tcRemaining, 1, ',', '.') ?> Std.</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Info-Bereich -->
<div class="row">
    <div class="col-12">
        <div class="card bg-light">
            <div class="card-body">
                <h6 class="card-title"><i class="bi bi-info-circle"></i> Ihre Rollen</h6>
                <p class="mb-0">
                    <?php foreach ($user->getRoles() as $role): ?>
                        <span class="badge bg-<?= match ($role) {
                            'administrator' => 'danger',
                            'pruefer' => 'warning text-dark',
                            'erfasser' => 'info',
                            'auditor' => 'secondary',
                            default => 'primary',
                        } ?> me-1">
                            <?= ViewHelper::e(match ($role) {
                                'mitglied' => 'Mitglied',
                                'erfasser' => 'Erfasser',
                                'pruefer' => 'Prüfer',
                                'auditor' => 'Auditor',
                                'administrator' => 'Administrator',
                                default => $role,
                            }) ?>
                        </span>
                    <?php endforeach; ?>
                </p>
            </div>
        </div>
    </div>
</div>

</div><!-- /dashboard-page -->
