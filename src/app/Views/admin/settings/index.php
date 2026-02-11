<?php
/**
 * Systemeinstellungen
 *
 * Variablen: $allSettings, $groups, $user, $settings
 */

use App\Helpers\ViewHelper;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-sliders"></i> Systemeinstellungen
    </h1>
</div>

<form method="POST" action="<?= ViewHelper::url('/admin/settings') ?>">
    <?= ViewHelper::csrfField() ?>

    <div class="accordion" id="settingsAccordion">
        <?php $index = 0; foreach ($groups as $groupKey => $group): ?>
        <?php if (empty($group['settings'])) continue; ?>
        <div class="accordion-item">
            <h2 class="accordion-header" id="heading-<?= $groupKey ?>">
                <button class="accordion-button <?= $index > 0 ? 'collapsed' : '' ?>" type="button"
                        data-bs-toggle="collapse" data-bs-target="#collapse-<?= $groupKey ?>">
                    <i class="bi <?= ViewHelper::e($group['icon']) ?> me-2"></i>
                    <?= ViewHelper::e($group['label']) ?>
                </button>
            </h2>
            <div id="collapse-<?= $groupKey ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>"
                 data-bs-parent="#settingsAccordion">
                <div class="accordion-body">
                    <?php foreach ($group['settings'] as $key => $setting): ?>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-4">
                            <label for="setting-<?= ViewHelper::e($key) ?>" class="form-label mb-0">
                                <?= ViewHelper::e(getSettingLabel($key)) ?>
                            </label>
                            <?php if ($setting['description']): ?>
                                <div class="form-text small"><?= ViewHelper::e($setting['description']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-8">
                            <?php if ($setting['setting_type'] === 'boolean'): ?>
                                <div class="form-check form-switch">
                                    <input type="checkbox" name="settings[<?= ViewHelper::e($key) ?>]"
                                           id="setting-<?= ViewHelper::e($key) ?>"
                                           class="form-check-input" value="true"
                                           <?= in_array(strtolower($setting['setting_value'] ?? ''), ['true', '1', 'yes', 'on']) ? 'checked' : '' ?>>
                                </div>
                            <?php elseif ($key === 'smtp_password'): ?>
                                <input type="password" name="settings[<?= ViewHelper::e($key) ?>]"
                                       id="setting-<?= ViewHelper::e($key) ?>"
                                       class="form-control form-control-sm"
                                       value="<?= ViewHelper::e($setting['setting_value'] ?? '') ?>"
                                       autocomplete="new-password">
                            <?php elseif ($key === 'smtp_encryption'): ?>
                                <select name="settings[<?= ViewHelper::e($key) ?>]"
                                        id="setting-<?= ViewHelper::e($key) ?>"
                                        class="form-select form-select-sm">
                                    <option value="tls" <?= ($setting['setting_value'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
                                    <option value="ssl" <?= ($setting['setting_value'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                </select>
                            <?php elseif (str_starts_with($key, 'field_') && str_ends_with($key, '_required')): ?>
                                <select name="settings[<?= ViewHelper::e($key) ?>]"
                                        id="setting-<?= ViewHelper::e($key) ?>"
                                        class="form-select form-select-sm">
                                    <option value="required" <?= ($setting['setting_value'] ?? '') === 'required' ? 'selected' : '' ?>>Pflichtfeld</option>
                                    <option value="optional" <?= ($setting['setting_value'] ?? '') === 'optional' ? 'selected' : '' ?>>Optional</option>
                                    <option value="hidden" <?= ($setting['setting_value'] ?? '') === 'hidden' ? 'selected' : '' ?>>Ausgeblendet</option>
                                </select>
                            <?php elseif ($setting['setting_type'] === 'integer'): ?>
                                <input type="number" name="settings[<?= ViewHelper::e($key) ?>]"
                                       id="setting-<?= ViewHelper::e($key) ?>"
                                       class="form-control form-control-sm"
                                       value="<?= ViewHelper::e($setting['setting_value'] ?? '0') ?>">
                            <?php else: ?>
                                <input type="text" name="settings[<?= ViewHelper::e($key) ?>]"
                                       id="setting-<?= ViewHelper::e($key) ?>"
                                       class="form-control form-control-sm"
                                       value="<?= ViewHelper::e($setting['setting_value'] ?? '') ?>">
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($groupKey === 'email'): ?>
                    <hr>
                    <p class="text-muted small mb-0">
                        <i class="bi bi-info-circle"></i>
                        Speichern Sie zuerst die Einstellungen, dann können Sie unten eine Test-E-Mail senden.
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php $index++; endforeach; ?>
    </div>

    <div class="mt-4">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-save"></i> Einstellungen speichern
        </button>
    </div>
</form>

<!-- Test-E-Mail (separates Formular, außerhalb des Hauptformulars) -->
<div class="mt-3">
    <form method="POST" action="<?= ViewHelper::url('/admin/settings/test-email') ?>" class="d-inline">
        <?= ViewHelper::csrfField() ?>
        <button type="submit" class="btn btn-sm btn-outline-info">
            <i class="bi bi-send"></i> Test-E-Mail senden
        </button>
        <span class="text-muted small ms-2">Sendet eine Test-E-Mail an Ihre Adresse.</span>
    </form>
</div>

<?php
// Helper-Funktion für Setting-Labels (lokal in der View)
function getSettingLabel(string $key): string
{
    return match ($key) {
        'app_name' => 'Anwendungsname',
        'vereinsname' => 'Vereinsname',
        'vereinslogo_path' => 'Logo-Pfad (PDF)',
        'session_timeout_minutes' => 'Session-Timeout (Min.)',
        'max_login_attempts' => 'Max. Fehlversuche',
        'lockout_duration_minutes' => 'Sperrdauer (Min.)',
        'require_2fa' => '2FA verpflichtend',
        'reminder_days' => 'Erinnerung nach (Tagen)',
        'reminder_enabled' => 'Erinnerungen aktiv',
        'target_hours_enabled' => 'Soll-Stunden aktiv',
        'target_hours_default' => 'Standard-Soll (Std.)',
        'data_retention_years' => 'Aufbewahrung (Jahre)',
        'invitation_expiry_days' => 'Einladung gültig (Tage)',
        'smtp_host' => 'SMTP-Server',
        'smtp_port' => 'SMTP-Port',
        'smtp_username' => 'SMTP-Benutzer',
        'smtp_password' => 'SMTP-Passwort',
        'smtp_encryption' => 'Verschlüsselung',
        'email_from_address' => 'Absender-Adresse',
        'email_from_name' => 'Absender-Name',
        'field_datum_required' => 'Datum',
        'field_zeit_von_required' => 'Uhrzeit von',
        'field_zeit_bis_required' => 'Uhrzeit bis',
        'field_stunden_required' => 'Stundenzahl',
        'field_kategorie_required' => 'Kategorie',
        'field_projekt_required' => 'Projekt',
        'field_beschreibung_required' => 'Beschreibung',
        'lock_timeout_minutes' => 'Sperr-Timeout (Min.)',
        default => $key,
    };
}
?>
