<?php
/**
 * Login-Formular
 *
 * Variablen: $reason, $settings
 */

use App\Helpers\ViewHelper;

$title = 'Anmeldung';
?>

<?php if ($reason === 'expired'): ?>
    <div class="alert alert-warning small">
        <i class="bi bi-clock"></i> Ihre Sitzung ist abgelaufen. Bitte melden Sie sich erneut an.
    </div>
<?php endif; ?>

<h5 class="card-title text-center mb-4">Anmeldung</h5>

<form method="post" action="<?= ViewHelper::url('/login') ?>" novalidate>
    <?= ViewHelper::csrfField() ?>

    <div class="mb-3">
        <label for="email" class="form-label">E-Mail-Adresse</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
            <input type="email" class="form-control" id="email" name="email"
                   value="<?= ViewHelper::old('email') ?>"
                   placeholder="ihre@email.de" required autofocus>
        </div>
    </div>

    <div class="mb-3">
        <label for="password" class="form-label">Passwort</label>
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-lock"></i></span>
            <input type="password" class="form-control" id="password" name="password"
                   placeholder="Passwort" required>
        </div>
    </div>

    <div class="d-grid mb-3">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-box-arrow-in-right"></i> Anmelden
        </button>
    </div>

    <div class="text-center">
        <a href="<?= ViewHelper::url('/forgot-password') ?>" class="small text-muted">Passwort vergessen?</a>
    </div>
</form>
