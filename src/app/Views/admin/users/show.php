<?php
/**
 * Benutzer-Detail
 *
 * Variablen: $targetUser, $allRoles, $invitation, $user, $settings
 */

use App\Helpers\ViewHelper;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-person"></i> <?= ViewHelper::e($targetUser->getVollname()) ?>
    </h1>
    <a href="<?= ViewHelper::url('/admin/users') ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
</div>

<div class="row g-4">
    <!-- Stammdaten -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-person-badge"></i> Stammdaten</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <th class="w-40">Mitgliedsnummer</th>
                        <td><?= ViewHelper::e($targetUser->getMitgliedsnummer()) ?></td>
                    </tr>
                    <tr>
                        <th>Name</th>
                        <td><?= ViewHelper::e($targetUser->getVollname()) ?></td>
                    </tr>
                    <tr>
                        <th>E-Mail</th>
                        <td><?= ViewHelper::e($targetUser->getEmail()) ?></td>
                    </tr>
                    <tr>
                        <th>Straße</th>
                        <td><?= ViewHelper::e($targetUser->getStrasse() ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>PLZ / Ort</th>
                        <td>
                            <?= ViewHelper::e($targetUser->getPlz() ?? '') ?>
                            <?= ViewHelper::e($targetUser->getOrt() ?? '-') ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Telefon</th>
                        <td><?= ViewHelper::e($targetUser->getTelefon() ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>Eintrittsdatum</th>
                        <td><?= $targetUser->getEintrittsdatum() ? ViewHelper::formatDate($targetUser->getEintrittsdatum()) : '-' ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Rollen -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-shield-check"></i> Rollen</div>
            <div class="card-body">
                <form method="POST" action="<?= ViewHelper::url('/admin/users/' . $targetUser->getId() . '/roles') ?>">
                    <?= ViewHelper::csrfField() ?>
                    <?php foreach ($allRoles as $role): ?>
                        <div class="form-check mb-2">
                            <input type="checkbox" name="roles[]" value="<?= $role['id'] ?>"
                                   id="role-<?= $role['id'] ?>" class="form-check-input"
                                   <?= in_array($role['name'], $targetUser->getRoles()) ? 'checked' : '' ?>>
                            <label for="role-<?= $role['id'] ?>" class="form-check-label">
                                <strong><?= ViewHelper::e(match ($role['name']) {
                                    'mitglied' => 'Mitglied',
                                    'erfasser' => 'Erfasser',
                                    'pruefer' => 'Prüfer',
                                    'auditor' => 'Auditor',
                                    'administrator' => 'Administrator',
                                    default => $role['name'],
                                }) ?></strong>
                                <span class="text-muted small d-block"><?= ViewHelper::e($role['description'] ?? '') ?></span>
                            </label>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-primary mt-2">
                        <i class="bi bi-save"></i> Rollen speichern
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Aktivität -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-activity"></i> Aktivität</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <th>Status</th>
                        <td>
                            <?php if ($targetUser->getDeletedAt() !== null): ?>
                                <span class="badge bg-danger">Gelöscht</span>
                            <?php elseif (!$targetUser->isActive()): ?>
                                <span class="badge bg-secondary">Inaktiv</span>
                            <?php else: ?>
                                <span class="badge bg-success">Aktiv</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>2FA</th>
                        <td>
                            <?php if ($targetUser->is2faEnabled()): ?>
                                <span class="badge bg-success">
                                    <i class="bi bi-shield-check"></i>
                                    <?= $targetUser->isTotpEnabled() ? 'TOTP' : 'E-Mail' ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Nicht eingerichtet</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Letzter Login</th>
                        <td><?= $targetUser->getLastLoginAt() ? ViewHelper::formatDateTime($targetUser->getLastLoginAt()) : 'Noch nie' ?></td>
                    </tr>
                    <tr>
                        <th>Erstellt am</th>
                        <td><?= ViewHelper::formatDateTime($targetUser->getCreatedAt()) ?></td>
                    </tr>
                    <tr>
                        <th>Fehlversuche</th>
                        <td>
                            <?= $targetUser->getFailedLoginAttempts() ?>
                            <?php if ($targetUser->isLocked()): ?>
                                <span class="badge bg-danger">Gesperrt bis
                                    <?= ViewHelper::formatDateTime($targetUser->getLockedUntil()) ?></span>
                            <?php endif; ?>
                            <?php if ($targetUser->isLocked() || $targetUser->getFailedLoginAttempts() > 0): ?>
                                <form method="POST" class="d-inline ms-2"
                                      action="<?= ViewHelper::url('/admin/users/' . $targetUser->getId() . '/unlock') ?>">
                                    <?= ViewHelper::csrfField() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-warning">
                                        <i class="bi bi-unlock"></i> Sperre aufheben
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Einladung -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-envelope"></i> Einladung</div>
            <div class="card-body">
                <?php if ($invitation): ?>
                    <table class="table table-sm mb-3">
                        <tr>
                            <th>Erstellt</th>
                            <td><?= ViewHelper::formatDateTime($invitation['created_at']) ?></td>
                        </tr>
                        <tr>
                            <th>Gesendet</th>
                            <td><?= $invitation['sent_at'] ? ViewHelper::formatDateTime($invitation['sent_at']) : 'Nicht gesendet' ?></td>
                        </tr>
                        <tr>
                            <th>Gültig bis</th>
                            <td><?= ViewHelper::formatDateTime($invitation['expires_at']) ?></td>
                        </tr>
                        <tr>
                            <th>Verwendet</th>
                            <td><?= $invitation['used_at'] ? ViewHelper::formatDateTime($invitation['used_at']) : 'Noch nicht verwendet' ?></td>
                        </tr>
                    </table>
                <?php else: ?>
                    <p class="text-muted small">Keine Einladung vorhanden.</p>
                <?php endif; ?>

                <form method="POST" action="<?= ViewHelper::url('/admin/users/' . $targetUser->getId() . '/reinvite') ?>" class="d-inline">
                    <?= ViewHelper::csrfField() ?>
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-send"></i> Neue Einladung senden
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Aktionen -->
    <div class="col-12">
        <div class="card border-danger">
            <div class="card-header text-danger"><i class="bi bi-exclamation-triangle"></i> Aktionen</div>
            <div class="card-body">
                <?php if ($targetUser->getDeletedAt() !== null): ?>
                    <div class="alert alert-secondary mb-0">
                        <i class="bi bi-trash"></i>
                        Dieses Mitglied ist gelöscht und kann über die Oberfläche nicht wiederhergestellt werden.
                        Die Datensätze bleiben aus Gründen der Revisionssicherheit erhalten.
                    </div>
                <?php elseif ($targetUser->getId() === $user->getId()): ?>
                    <span class="text-muted">Sie können Ihr eigenes Konto nicht deaktivieren oder löschen.</span>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($targetUser->isActive()): ?>
                            <form method="POST" action="<?= ViewHelper::url('/admin/users/' . $targetUser->getId() . '/deactivate') ?>"
                                  class="d-inline" onsubmit="return confirm('Mitglied wirklich deaktivieren? Der Account bleibt in der Liste und kann später wieder aktiviert werden.');">
                                <?= ViewHelper::csrfField() ?>
                                <button type="submit" class="btn btn-warning">
                                    <i class="bi bi-person-x"></i> Deaktivieren
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="POST" action="<?= ViewHelper::url('/admin/users/' . $targetUser->getId() . '/activate') ?>" class="d-inline">
                                <?= ViewHelper::csrfField() ?>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-person-check"></i> Aktivieren
                                </button>
                            </form>
                        <?php endif; ?>

                        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteUserModal">
                            <i class="bi bi-trash"></i> Löschen
                        </button>
                    </div>

                    <!-- Löschen-Bestätigungsmodal -->
                    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header bg-danger text-white">
                                    <h5 class="modal-title" id="deleteUserModalLabel">
                                        <i class="bi bi-exclamation-triangle"></i> Mitglied endgültig löschen?
                                    </h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Schließen"></button>
                                </div>
                                <div class="modal-body">
                                    <p>
                                        Mitglied
                                        <strong><?= ViewHelper::e($targetUser->getVollname()) ?></strong>
                                        (<?= ViewHelper::e($targetUser->getMitgliedsnummer()) ?>)
                                        wird aus der Mitgliederliste entfernt.
                                    </p>
                                    <ul class="mb-0">
                                        <li>Der Account kann über die Oberfläche <strong>nicht wiederhergestellt</strong> werden.</li>
                                        <li>Historische Anträge und Audit-Einträge bleiben erhalten.</li>
                                        <li>Wenn Sie den Zugang nur zeitweise sperren wollen, nutzen Sie stattdessen <em>Deaktivieren</em>.</li>
                                    </ul>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                                    <form method="POST" action="<?= ViewHelper::url('/admin/users/' . $targetUser->getId() . '/delete') ?>" class="d-inline">
                                        <?= ViewHelper::csrfField() ?>
                                        <button type="submit" class="btn btn-danger">
                                            <i class="bi bi-trash"></i> Endgültig löschen
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
