<?php
/**
 * 2FA-Code-Eingabe
 *
 * Variablen: $method (totp|email), $settings
 */

use App\Helpers\ViewHelper;

$title = 'Zwei-Faktor-Authentifizierung';
?>

<h5 class="card-title text-center mb-4">Zwei-Faktor-Authentifizierung</h5>

<?php if ($method === 'totp'): ?>
    <p class="text-muted small text-center mb-4">
        <i class="bi bi-phone"></i>
        Geben Sie den Code aus Ihrer Authenticator-App ein.
    </p>
<?php else: ?>
    <p class="text-muted small text-center mb-4">
        <i class="bi bi-envelope"></i>
        Ein 6-stelliger Code wurde an Ihre E-Mail-Adresse gesendet.
    </p>
<?php endif; ?>

<form method="post" action="<?= ViewHelper::url('/2fa') ?>" novalidate>
    <?= ViewHelper::csrfField() ?>

    <div class="mb-4">
        <label for="code" class="form-label">Verifizierungscode</label>
        <input type="text" class="form-control form-control-lg text-center"
               id="code" name="code"
               maxlength="6" pattern="[0-9]{6}"
               placeholder="000000"
               autocomplete="one-time-code"
               inputmode="numeric"
               required autofocus>
    </div>

    <div class="d-grid mb-3">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-shield-check"></i> Verifizieren
        </button>
    </div>

    <div class="text-center">
        <a href="<?= ViewHelper::url('/login') ?>" class="small text-muted">Zurück zur Anmeldung</a>
    </div>
</form>
