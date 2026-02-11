<?php
/**
 * Arbeitsstunden-Eintrag Detailansicht
 *
 * Variablen: $entry, $dialogs, $user
 */

use App\Helpers\ViewHelper;

$isOwner = ($entry->getUserId() === $user->getId() || $entry->getCreatedByUserId() === $user->getId());
$isReviewer = ($user->canReview() && $entry->getUserId() !== $user->getId() && $entry->getCreatedByUserId() !== $user->getId());
?>

<div class="row justify-content-center">
    <div class="col-lg-10">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-0">
                    Antrag <?= ViewHelper::e($entry->getEntryNumber()) ?>
                    <span class="badge <?= ViewHelper::e($entry->getStatusBadge()) ?> ms-2">
                        <?= ViewHelper::e($entry->getStatusLabel()) ?>
                    </span>
                </h1>
                <small class="text-muted">
                    Erstellt am <?= ViewHelper::formatDateTime($entry->getCreatedAt()) ?>
                    <?php if (!$entry->isSelfEntry()): ?>
                        von <?= ViewHelper::e($entry->getCreatedByName()) ?>
                        für <?= ViewHelper::e($entry->getUserName()) ?>
                    <?php else: ?>
                        von <?= ViewHelper::e($entry->getUserName()) ?>
                    <?php endif; ?>
                </small>
            </div>
            <a href="<?= ViewHelper::url($isReviewer && !$isOwner ? '/review' : '/entries') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Zurück
            </a>
        </div>

        <div class="row">
            <!-- Linke Spalte: Details -->
            <div class="col-lg-7">
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-info-circle"></i> Details
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless mb-0">
                            <tr>
                                <th class="text-muted" style="width: 40%">Datum</th>
                                <td><?= ViewHelper::formatDate($entry->getWorkDate()) ?></td>
                            </tr>
                            <?php if ($entry->getTimeFrom() && $entry->getTimeTo()): ?>
                            <tr>
                                <th class="text-muted">Uhrzeit</th>
                                <td><?= ViewHelper::e($entry->getTimeFrom()) ?> - <?= ViewHelper::e($entry->getTimeTo()) ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th class="text-muted">Stunden</th>
                                <td>
                                    <strong><?= ViewHelper::formatHours($entry->getHours()) ?> h</strong>
                                    <?php if ($entry->isCorrected()): ?>
                                        <br>
                                        <small class="text-info">
                                            <i class="bi bi-pencil"></i>
                                            Korrigiert (vorher: <?= ViewHelper::formatHours($entry->getOriginalHours()) ?> h)
                                        </small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">Kategorie</th>
                                <td><?= ViewHelper::e($entry->getCategoryName() ?? '-') ?></td>
                            </tr>
                            <?php if ($entry->getProject()): ?>
                            <tr>
                                <th class="text-muted">Projekt</th>
                                <td><?= ViewHelper::e($entry->getProject()) ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ($entry->getDescription()): ?>
                            <tr>
                                <th class="text-muted">Beschreibung</th>
                                <td><?= nl2br(ViewHelper::e($entry->getDescription())) ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

                <!-- Workflow-Info -->
                <?php if ($entry->getReviewedByName()): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <i class="bi bi-clipboard-check"></i> Prüfung
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless mb-0">
                            <tr>
                                <th class="text-muted" style="width: 40%">Geprüft von</th>
                                <td><?= ViewHelper::e($entry->getReviewedByName()) ?></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Geprüft am</th>
                                <td><?= ViewHelper::formatDateTime($entry->getReviewedAt()) ?></td>
                            </tr>
                            <?php if ($entry->getRejectionReason()): ?>
                            <tr>
                                <th class="text-muted">Ablehnungsgrund</th>
                                <td class="text-danger"><?= nl2br(ViewHelper::e($entry->getRejectionReason())) ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($entry->getReturnReason() && $entry->getStatus() === 'in_klaerung'): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-question-circle"></i>
                    <strong>Rückfrage:</strong>
                    <?= nl2br(ViewHelper::e($entry->getReturnReason())) ?>
                </div>
                <?php endif; ?>

                <?php if ($entry->isCorrected() && $entry->getCorrectionReason()): ?>
                <div class="alert alert-info">
                    <i class="bi bi-pencil-square"></i>
                    <strong>Korrektur-Begründung:</strong>
                    <?= nl2br(ViewHelper::e($entry->getCorrectionReason())) ?>
                </div>
                <?php endif; ?>

                <!-- Aktions-Buttons -->
                <div class="card mb-4">
                    <div class="card-body d-flex flex-wrap gap-2">
                        <?php if ($isOwner && $entry->isEditable()): ?>
                            <a href="<?= ViewHelper::url('/entries/' . $entry->getId() . '/edit') ?>" class="btn btn-primary btn-sm">
                                <i class="bi bi-pencil"></i> Bearbeiten
                            </a>
                            <form method="POST" action="<?= ViewHelper::url('/entries/' . $entry->getId() . '/submit') ?>" class="d-inline">
                                <?= ViewHelper::csrfField() ?>
                                <button type="submit" class="btn btn-success btn-sm">
                                    <i class="bi bi-send"></i> Einreichen
                                </button>
                            </form>
                            <form method="POST" action="<?= ViewHelper::url('/entries/' . $entry->getId() . '/delete') ?>"
                                  class="d-inline" onsubmit="return confirm('Eintrag wirklich löschen?')">
                                <?= ViewHelper::csrfField() ?>
                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                    <i class="bi bi-trash"></i> Löschen
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($isOwner && $entry->isWithdrawable()): ?>
                            <form method="POST" action="<?= ViewHelper::url('/entries/' . $entry->getId() . '/withdraw') ?>" class="d-inline">
                                <?= ViewHelper::csrfField() ?>
                                <button type="submit" class="btn btn-warning btn-sm"
                                        onclick="return confirm('Antrag wirklich zurückziehen?')">
                                    <i class="bi bi-arrow-counterclockwise"></i> Zurückziehen
                                </button>
                            </form>
                            <form method="POST" action="<?= ViewHelper::url('/entries/' . $entry->getId() . '/cancel') ?>" class="d-inline">
                                <?= ViewHelper::csrfField() ?>
                                <button type="submit" class="btn btn-outline-dark btn-sm"
                                        onclick="return confirm('Antrag wirklich stornieren?')">
                                    <i class="bi bi-x-circle"></i> Stornieren
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($isOwner && $entry->isReactivatable()): ?>
                            <form method="POST" action="<?= ViewHelper::url('/entries/' . $entry->getId() . '/reactivate') ?>" class="d-inline">
                                <?= ViewHelper::csrfField() ?>
                                <button type="submit" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-arrow-repeat"></i> Reaktivieren
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($isReviewer && in_array($entry->getStatus(), ['eingereicht', 'in_klaerung'])): ?>
                            <form method="POST" action="<?= ViewHelper::url('/entries/' . $entry->getId() . '/approve') ?>" class="d-inline">
                                <?= ViewHelper::csrfField() ?>
                                <button type="submit" class="btn btn-success btn-sm"
                                        onclick="return confirm('Antrag freigeben?')">
                                    <i class="bi bi-check-lg"></i> Freigeben
                                </button>
                            </form>

                            <button type="button" class="btn btn-warning btn-sm"
                                    data-bs-toggle="modal" data-bs-target="#returnModal">
                                <i class="bi bi-question-circle"></i> Rückfrage
                            </button>

                            <button type="button" class="btn btn-danger btn-sm"
                                    data-bs-toggle="modal" data-bs-target="#rejectModal">
                                <i class="bi bi-x-lg"></i> Ablehnen
                            </button>
                        <?php endif; ?>

                        <?php if ($isReviewer && $entry->getStatus() === 'freigegeben'): ?>
                            <button type="button" class="btn btn-outline-info btn-sm"
                                    data-bs-toggle="modal" data-bs-target="#correctModal">
                                <i class="bi bi-pencil-square"></i> Korrektur
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Rechte Spalte: Dialog -->
            <div class="col-lg-5">
                <?php require __DIR__ . '/_dialog.php'; ?>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Rückfrage -->
<?php if ($isReviewer): ?>
<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= ViewHelper::url('/entries/' . $entry->getId() . '/return') ?>">
                <?= ViewHelper::csrfField() ?>
                <div class="modal-header">
                    <h5 class="modal-title">Rückfrage stellen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="return-reason" class="form-label">Rückfrage / Begründung <span class="text-danger">*</span></label>
                        <textarea name="reason" id="return-reason" class="form-control" rows="4" required
                                  placeholder="Was soll geklärt werden?"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-question-circle"></i> Rückfrage senden
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Ablehnung -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= ViewHelper::url('/entries/' . $entry->getId() . '/reject') ?>">
                <?= ViewHelper::csrfField() ?>
                <div class="modal-header">
                    <h5 class="modal-title">Antrag ablehnen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="reject-reason" class="form-label">Begründung <span class="text-danger">*</span></label>
                        <textarea name="reason" id="reject-reason" class="form-control" rows="4" required
                                  placeholder="Warum wird der Antrag abgelehnt?"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-lg"></i> Ablehnen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Korrektur -->
<div class="modal fade" id="correctModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= ViewHelper::url('/entries/' . $entry->getId() . '/correct') ?>">
                <?= ViewHelper::csrfField() ?>
                <div class="modal-header">
                    <h5 class="modal-title">Stunden korrigieren</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Aktuelle Stunden</label>
                        <input type="text" class="form-control" value="<?= ViewHelper::formatHours($entry->getHours()) ?> h" disabled>
                    </div>
                    <div class="mb-3">
                        <label for="correct-hours" class="form-label">Neue Stunden <span class="text-danger">*</span></label>
                        <input type="number" name="hours" id="correct-hours"
                               class="form-control" step="0.25" min="0.25" max="24" required>
                    </div>
                    <div class="mb-3">
                        <label for="correct-reason" class="form-label">Begründung <span class="text-danger">*</span></label>
                        <textarea name="reason" id="correct-reason" class="form-control" rows="3" required
                                  placeholder="Warum wird korrigiert?"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-info">
                        <i class="bi bi-pencil-square"></i> Korrektur speichern
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
