<?php
/**
 * Audit-Eintrag Detail
 *
 * Variablen: $entry, $oldValues, $newValues, $metadata, $user, $settings
 */

use App\Helpers\ViewHelper;

$actionLabels = [
    'create' => 'Erstellt',
    'update' => 'Aktualisiert',
    'delete' => 'Gelöscht',
    'restore' => 'Wiederhergestellt',
    'login' => 'Anmeldung',
    'logout' => 'Abmeldung',
    'login_failed' => 'Login fehlgeschlagen',
    'status_change' => 'Statusänderung',
    'export' => 'Export',
    'import' => 'Import',
    'config_change' => 'Konfigurationsänderung',
    'dialog_message' => 'Dialog-Nachricht',
];

$basePath = $user->isAdmin() ? '/admin/audit' : '/audit';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-journal-text"></i> Audit-Detail #<?= $entry['id'] ?>
    </h1>
    <a href="<?= $basePath ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
</div>

<div class="row g-4">
    <!-- Metadaten -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle"></i> Metadaten</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <th class="w-40">Zeitpunkt</th>
                        <td><?= ViewHelper::formatDateTime($entry['created_at']) ?></td>
                    </tr>
                    <tr>
                        <th>Benutzer</th>
                        <td><?= ViewHelper::e($entry['user_name'] ?? 'System') ?> (ID: <?= $entry['user_id'] ?? '-' ?>)</td>
                    </tr>
                    <tr>
                        <th>IP-Adresse</th>
                        <td><?= ViewHelper::e($entry['ip_address'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>User-Agent</th>
                        <td class="small text-break"><?= ViewHelper::e($entry['user_agent'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>Session-ID</th>
                        <td><?= ViewHelper::e((string) ($entry['session_id'] ?? '-')) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Aktion -->
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-lightning"></i> Aktion</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <th class="w-40">Aktion</th>
                        <td>
                            <span class="badge bg-primary">
                                <?= ViewHelper::e($actionLabels[$entry['action']] ?? $entry['action']) ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Tabelle</th>
                        <td><?= ViewHelper::e($entry['table_name'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>Datensatz-ID</th>
                        <td><?= $entry['record_id'] ?? '-' ?></td>
                    </tr>
                    <tr>
                        <th>Antragsnummer</th>
                        <td><?= ViewHelper::e($entry['entry_number'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <th>Beschreibung</th>
                        <td><?= ViewHelper::e($entry['description'] ?? '-') ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Alte Werte -->
    <?php if ($oldValues !== null): ?>
    <div class="col-md-6">
        <div class="card border-danger">
            <div class="card-header text-danger"><i class="bi bi-dash-circle"></i> Alte Werte</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <?php foreach ($oldValues as $key => $value): ?>
                    <tr>
                        <th class="w-40"><?= ViewHelper::e($key) ?></th>
                        <td class="text-danger">
                            <?php if (is_array($value)): ?>
                                <pre class="mb-0 small"><?= ViewHelper::e(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                            <?php else: ?>
                                <?= ViewHelper::e((string) ($value ?? 'NULL')) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Neue Werte -->
    <?php if ($newValues !== null): ?>
    <div class="col-md-6">
        <div class="card border-success">
            <div class="card-header text-success"><i class="bi bi-plus-circle"></i> Neue Werte</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <?php foreach ($newValues as $key => $value): ?>
                    <tr>
                        <th class="w-40"><?= ViewHelper::e($key) ?></th>
                        <td class="text-success">
                            <?php if (is_array($value)): ?>
                                <pre class="mb-0 small"><?= ViewHelper::e(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                            <?php else: ?>
                                <?= ViewHelper::e((string) ($value ?? 'NULL')) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Metadaten (zusätzlich) -->
    <?php if ($metadata !== null): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-braces"></i> Zusätzliche Metadaten</div>
            <div class="card-body">
                <pre class="mb-0 small"><?= ViewHelper::e(json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
