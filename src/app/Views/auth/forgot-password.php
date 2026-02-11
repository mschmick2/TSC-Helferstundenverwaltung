<?php
/**
 * Passwort vergessen
 *
 * Variablen: $settings
 */

use App\Helpers\ViewHelper;

$title = 'Passwort vergessen';
?>

<h5 class="card-title text-center mb-4">Passwort vergessen</h5>

<p class="text-muted small text-center mb-4">
    Geben Sie Ihre E-Mail-Adresse ein. Sie erhalten einen Link zum Zurücksetzen Ihres Passworts.
</p>

<form method="post" action="<?= ViewHelper::url('/forgot-password') ?>" novalidate>
    <?= ViewHelper::csrfField() ?>

    <div class="mb-4">
        <label for="email" class="form-label">E-Mail-Adresse</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" class="form-control" id="email" name="email"
                   placeholder="ihre@email.de" required autofocus>
        </div>
    </div>

    <div class="d-grid mb-3">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-send"></i> Reset-Link anfordern
        </button>
    </div>

    <div class="text-center">
        <a href="<?= ViewHelper::url('/login') ?>" class="small text-muted">Zurück zur Anmeldung</a>
    </div>
</form>
