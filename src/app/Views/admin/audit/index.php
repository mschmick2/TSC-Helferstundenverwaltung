<?php
/**
 * Audit-Trail Übersicht
 *
 * Variablen: $entries, $total, $page, $perPage, $actions, $tableNames, $filters, $user, $settings
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
    'config_change' => 'Konfiguration',
    'dialog_message' => 'Dialog',
];

$actionBadges = [
    'create' => 'bg-success',
    'update' => 'bg-primary',
    'delete' => 'bg-danger',
    'restore' => 'bg-info',
    'login' => 'bg-secondary',
    'logout' => 'bg-secondary',
    'login_failed' => 'bg-warning text-dark',
    'status_change' => 'bg-info',
    'export' => 'bg-dark',
    'import' => 'bg-dark',
    'config_change' => 'bg-warning text-dark',
    'dialog_message' => 'bg-light text-dark',
];

$basePath = $user->isAdmin() ? '/admin/audit' : '/audit';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-journal-text"></i> Audit-Trail
    </h1>
    <span class="badge bg-secondary"><?= $total ?> Einträge</span>
</div>

<!-- Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="<?= $basePath ?>" class="row g-3">
            <div class="col-md-2">
                <label for="filter-action" class="form-label">Aktion</label>
                <select name="action" id="filter-action" class="form-select form-select-sm">
                    <option value="">Alle</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= ViewHelper::e($a) ?>" <?= ($filters['action'] ?? '') === $a ? 'selected' : '' ?>>
                            <?= ViewHelper::e($actionLabels[$a] ?? $a) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="filter-table" class="form-label">Tabelle</label>
                <select name="table_name" id="filter-table" class="form-select form-select-sm">
                    <option value="">Alle</option>
                    <?php foreach ($tableNames as $t): ?>
                        <option value="<?= ViewHelper::e($t) ?>" <?= ($filters['table_name'] ?? '') === $t ? 'selected' : '' ?>>
                            <?= ViewHelper::e($t) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="filter-from" class="form-label">Datum von</label>
                <input type="date" name="date_from" id="filter-from" class="form-control form-control-sm"
                       value="<?= ViewHelper::e($filters['date_from'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label for="filter-to" class="form-label">Datum bis</label>
                <input type="date" name="date_to" id="filter-to" class="form-control form-control-sm"
                       value="<?= ViewHelper::e($filters['date_to'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label for="filter-entry" class="form-label">Antragsnr.</label>
                <input type="text" name="entry_number" id="filter-entry" class="form-control form-control-sm"
                       placeholder="z.B. 2025-00001"
                       value="<?= ViewHelper::e($filters['entry_number'] ?? '') ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-funnel"></i> Filtern
                </button>
                <a href="<?= $basePath ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Tabelle -->
<?php if (empty($entries)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-journal display-4"></i>
        <p class="mt-2">Keine Audit-Einträge gefunden.</p>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover table-sm align-middle">
            <thead class="table-light">
                <tr>
                    <th>Datum/Zeit</th>
                    <th>Benutzer</th>
                    <th>Aktion</th>
                    <th>Tabelle</th>
                    <th>Antragsnr.</th>
                    <th>Beschreibung</th>
                    <th class="text-end">Detail</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry): ?>
                <tr>
                    <td class="small text-nowrap"><?= ViewHelper::formatDateTime($entry['created_at']) ?></td>
                    <td class="small"><?= ViewHelper::e($entry['user_name'] ?? 'System') ?></td>
                    <td>
                        <span class="badge <?= ViewHelper::e($actionBadges[$entry['action']] ?? 'bg-secondary') ?>">
                            <?= ViewHelper::e($actionLabels[$entry['action']] ?? $entry['action']) ?>
                        </span>
                    </td>
                    <td class="small text-muted"><?= ViewHelper::e($entry['table_name'] ?? '-') ?></td>
                    <td class="small"><?= ViewHelper::e($entry['entry_number'] ?? '-') ?></td>
                    <td class="small text-truncate" style="max-width: 300px;"><?= ViewHelper::e($entry['description'] ?? '-') ?></td>
                    <td class="text-end">
                        <a href="<?= $basePath ?>/<?= $entry['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Detail">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php require __DIR__ . '/../../components/_pagination.php'; ?>
<?php endif; ?>
