<?php
/**
 * Passwort zurücksetzen
 *
 * Variablen: $token, $settings
 */

use App\Helpers\ViewHelper;

$title = 'Passwort zurücksetzen';
?>

<h5 class="card-title text-center mb-4">Neues Passwort setzen</h5>

<form method="post" action="<?= ViewHelper::url('/reset-password/' . ViewHelper::e($token)) ?>" novalidate>
    <?= ViewHelper::csrfField() ?>

    <div class="mb-3">
        <label for="password" class="form-label">Neues Passwort</label>
        <input type="password" class="form-control" id="password" name="password" required autofocus>
        <div class="form-text">
            Min. 8 Zeichen, Groß-/Kleinbuchstaben und Ziffern.
        </div>
    </div>

    <div class="mb-4">
        <label for="password_confirm" class="form-label">Passwort bestätigen</label>
        <input type="password" class="form-control" id="password_confirm" name="password_confirm" required>
    </div>

    <div class="d-grid mb-3">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> Passwort ändern
        </button>
    </div>

    <div class="text-center">
        <a href="<?= ViewHelper::url('/login') ?>" class="small text-muted">Zurück zur Anmeldung</a>
    </div>
</form>
