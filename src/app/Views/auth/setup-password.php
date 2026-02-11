<?php
/**
 * Passwort setzen (Einladungslink)
 *
 * Variablen: $token, $vorname, $settings
 */

use App\Helpers\ViewHelper;

$title = 'Passwort einrichten';
?>

<h5 class="card-title text-center mb-4">Willkommen, <?= ViewHelper::e($vorname) ?>!</h5>

<p class="text-muted small text-center mb-4">
    Bitte setzen Sie Ihr Passwort, um Ihr Konto zu aktivieren.
</p>

<form method="post" action="<?= ViewHelper::url('/setup-password/' . ViewHelper::e($token)) ?>" novalidate>
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

    <div class="d-grid">
        <button type="submit" class="btn btn-success">
            <i class="bi bi-check-lg"></i> Passwort setzen
        </button>
    </div>
</form>
