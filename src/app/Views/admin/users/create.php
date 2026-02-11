<?php
/**
 * Neues Mitglied manuell anlegen
 *
 * Variablen: $user, $settings, $allRoles
 */

use App\Helpers\ViewHelper;

$title = 'Neues Mitglied anlegen';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-person-plus"></i> Neues Mitglied anlegen
    </h1>
    <a href="<?= ViewHelper::url('/admin/users') ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
</div>

<p class="text-danger small mb-4"><i class="bi bi-asterisk"></i> Pflichtfeld</p>

<form method="POST" action="<?= ViewHelper::url('/admin/users/create') ?>">
    <?= ViewHelper::csrfField() ?>

    <!-- Stammdaten -->
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-person"></i> Stammdaten</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="mitgliedsnummer" class="form-label">
                        Mitgliedsnummer <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="mitgliedsnummer" id="mitgliedsnummer" class="form-control"
                           value="<?= ViewHelper::old('mitgliedsnummer') ?>" maxlength="50" required>
                </div>
                <div class="col-md-6">
                    <label for="email" class="form-label">
                        E-Mail <span class="text-danger">*</span>
                    </label>
                    <input type="email" name="email" id="email" class="form-control"
                           value="<?= ViewHelper::old('email') ?>" maxlength="255" required>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-6">
                    <label for="vorname" class="form-label">
                        Vorname <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="vorname" id="vorname" class="form-control"
                           value="<?= ViewHelper::old('vorname') ?>" maxlength="100" required>
                </div>
                <div class="col-md-6">
                    <label for="nachname" class="form-label">
                        Nachname <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="nachname" id="nachname" class="form-control"
                           value="<?= ViewHelper::old('nachname') ?>" maxlength="100" required>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-6">
                    <label for="strasse" class="form-label">Straße</label>
                    <input type="text" name="strasse" id="strasse" class="form-control"
                           value="<?= ViewHelper::old('strasse') ?>" maxlength="255">
                </div>
                <div class="col-md-6">
                    <label for="telefon" class="form-label">Telefon</label>
                    <input type="text" name="telefon" id="telefon" class="form-control"
                           value="<?= ViewHelper::old('telefon') ?>" maxlength="50">
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-4">
                    <label for="plz" class="form-label">PLZ</label>
                    <input type="text" name="plz" id="plz" class="form-control"
                           value="<?= ViewHelper::old('plz') ?>" maxlength="20">
                </div>
                <div class="col-md-4">
                    <label for="ort" class="form-label">Ort</label>
                    <input type="text" name="ort" id="ort" class="form-control"
                           value="<?= ViewHelper::old('ort') ?>" maxlength="100">
                </div>
                <div class="col-md-4">
                    <label for="eintrittsdatum" class="form-label">Eintrittsdatum</label>
                    <input type="date" name="eintrittsdatum" id="eintrittsdatum" class="form-control"
                           value="<?= ViewHelper::old('eintrittsdatum') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Rollen -->
    <?php $oldRoles = $_SESSION['_old_input']['roles'] ?? null; ?>
    <div class="card mb-4">
        <div class="card-header"><i class="bi bi-shield-check"></i> Rollen</div>
        <div class="card-body">
            <?php foreach ($allRoles as $role): ?>
                <div class="form-check mb-2">
                    <input type="checkbox" name="roles[]" value="<?= $role['id'] ?>"
                           id="role-<?= $role['id'] ?>" class="form-check-input"
                           <?= $oldRoles !== null
                               ? (in_array($role['id'], $oldRoles) ? 'checked' : '')
                               : ($role['name'] === 'mitglied' ? 'checked' : '') ?>>
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
        </div>
    </div>

    <!-- Hinweis -->
    <div class="alert alert-info small">
        <i class="bi bi-info-circle"></i>
        Nach dem Anlegen wird automatisch eine Einladungs-E-Mail an das neue Mitglied gesendet.
        Das Mitglied kann dann über den Link ein Passwort setzen und die 2FA einrichten.
    </div>

    <!-- Buttons -->
    <div class="d-flex justify-content-between">
        <a href="<?= ViewHelper::url('/admin/users') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-x-lg"></i> Abbrechen
        </a>
        <button type="submit" class="btn btn-success">
            <i class="bi bi-person-plus"></i> Mitglied anlegen
        </button>
    </div>
</form>
