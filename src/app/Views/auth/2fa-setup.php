<?php
/**
 * 2FA-Einrichtung
 *
 * Variablen: $user, $secret, $provisioningUri, $qrCodeDataUri, $settings
 */

use App\Helpers\ViewHelper;

$title = '2FA einrichten';
?>

<h5 class="card-title text-center mb-4">Zwei-Faktor-Authentifizierung einrichten</h5>

<p class="text-muted small">
    Die Zwei-Faktor-Authentifizierung erhöht die Sicherheit Ihres Accounts.
    Wählen Sie eine der folgenden Methoden:
</p>

<!-- Tab-Navigation -->
<ul class="nav nav-pills nav-fill mb-4" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#totp-tab" type="button" role="tab">
            <i class="bi bi-phone"></i> Authenticator-App
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="pill" data-bs-target="#email-tab" type="button" role="tab">
            <i class="bi bi-envelope"></i> E-Mail-Code
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- TOTP-Tab -->
    <div class="tab-pane fade show active" id="totp-tab" role="tabpanel">
        <div class="text-center mb-3">
            <p class="small">Scannen Sie den QR-Code mit Ihrer Authenticator-App:</p>

            <!-- QR-Code (serverseitig generiert) -->
            <div class="mb-3">
                <img src="<?= $qrCodeDataUri ?>"
                     alt="QR-Code" class="img-fluid border rounded" style="max-width: 200px;">
            </div>

            <!-- Manueller Code -->
            <details class="mb-3">
                <summary class="small text-muted">Code manuell eingeben</summary>
                <code class="d-block mt-2 p-2 bg-light rounded small user-select-all">
                    <?= ViewHelper::e($secret) ?>
                </code>
            </details>
        </div>

        <form method="post" action="<?= ViewHelper::url('/2fa-setup') ?>">
            <?= ViewHelper::csrfField() ?>
            <input type="hidden" name="method" value="totp">

            <div class="mb-3">
                <label for="totp-code" class="form-label">Bestätigungscode eingeben</label>
                <input type="text" class="form-control text-center" id="totp-code" name="code"
                       maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                       inputmode="numeric" required>
                <div class="form-text">Geben Sie den 6-stelligen Code aus Ihrer App ein.</div>
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-shield-check"></i> Authenticator-App aktivieren
                </button>
            </div>
        </form>
    </div>

    <!-- E-Mail-Tab -->
    <div class="tab-pane fade" id="email-tab" role="tabpanel">
        <p class="small text-center mb-3">
            Bei jeder Anmeldung wird ein 6-stelliger Code an
            <strong><?= ViewHelper::e($user->getEmail()) ?></strong> gesendet.
        </p>

        <form method="post" action="<?= ViewHelper::url('/2fa-setup') ?>">
            <?= ViewHelper::csrfField() ?>
            <input type="hidden" name="method" value="email">
            <input type="hidden" name="code" value="">

            <div class="d-grid">
                <button type="submit" class="btn btn-outline-primary">
                    <i class="bi bi-envelope-check"></i> E-Mail-Code aktivieren
                </button>
            </div>
        </form>
    </div>
</div>
