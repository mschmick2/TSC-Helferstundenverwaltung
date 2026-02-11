<?php
/**
 * Mitgliederverwaltung - Übersicht
 *
 * Variablen: $users, $total, $page, $perPage, $roles, $filters, $user, $settings
 */

use App\Helpers\ViewHelper;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-people"></i> Mitglieder verwalten
    </h1>
    <div class="d-flex gap-2">
        <a href="<?= ViewHelper::url('/admin/users/create') ?>" class="btn btn-success">
            <i class="bi bi-person-plus"></i> Neues Mitglied
        </a>
        <a href="<?= ViewHelper::url('/admin/users/import') ?>" class="btn btn-primary">
            <i class="bi bi-upload"></i> CSV-Import
        </a>
    </div>
</div>

<!-- Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="<?= ViewHelper::url('/admin/users') ?>" class="row g-3">
            <div class="col-md-4">
                <label for="filter-search" class="form-label">Suche</label>
                <input type="text" name="search" id="filter-search" class="form-control form-control-sm"
                       placeholder="Name, E-Mail oder Mitgliedsnr."
                       value="<?= ViewHelper::e($filters['search'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label for="filter-role" class="form-label">Rolle</label>
                <select name="role" id="filter-role" class="form-select form-select-sm">
                    <option value="">Alle</option>
                    <?php foreach ($roles as $role): ?>
                        <option value="<?= ViewHelper::e($role['name']) ?>"
                                <?= ($filters['role'] ?? '') === $role['name'] ? 'selected' : '' ?>>
                            <?= ViewHelper::e(match ($role['name']) {
                                'mitglied' => 'Mitglied',
                                'erfasser' => 'Erfasser',
                                'pruefer' => 'Prüfer',
                                'auditor' => 'Auditor',
                                'administrator' => 'Administrator',
                                default => $role['name'],
                            }) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <div class="form-check mt-2">
                    <input type="checkbox" name="inactive" value="1" id="filter-inactive"
                           class="form-check-input" <?= ($filters['inactive'] ?? false) ? 'checked' : '' ?>>
                    <label for="filter-inactive" class="form-check-label small">Inaktive zeigen</label>
                </div>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-funnel"></i> Filtern
                </button>
                <a href="<?= ViewHelper::url('/admin/users') ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Tabelle -->
<?php if (empty($users)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-people display-4"></i>
        <p class="mt-2">Keine Mitglieder gefunden.</p>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>Mitgliedsnr.</th>
                    <th>Name</th>
                    <th>E-Mail</th>
                    <th>Rollen</th>
                    <th class="text-center">Status</th>
                    <th>Letzter Login</th>
                    <th class="text-end">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $member): ?>
                <tr class="<?= $member->getDeletedAt() !== null ? 'table-secondary' : '' ?>">
                    <td class="fw-semibold"><?= ViewHelper::e($member->getMitgliedsnummer()) ?></td>
                    <td>
                        <a href="<?= ViewHelper::url('/admin/users/' . $member->getId()) ?>" class="text-decoration-none">
                            <?= ViewHelper::e($member->getVollname()) ?>
                        </a>
                    </td>
                    <td class="small"><?= ViewHelper::e($member->getEmail()) ?></td>
                    <td>
                        <?php foreach ($member->getRoles() as $r): ?>
                            <span class="badge bg-<?= match ($r) {
                                'administrator' => 'danger',
                                'pruefer' => 'warning text-dark',
                                'erfasser' => 'info',
                                'auditor' => 'secondary',
                                default => 'primary',
                            } ?> me-1">
                                <?= ViewHelper::e(match ($r) {
                                    'mitglied' => 'Mitglied',
                                    'erfasser' => 'Erfasser',
                                    'pruefer' => 'Prüfer',
                                    'auditor' => 'Auditor',
                                    'administrator' => 'Admin',
                                    default => $r,
                                }) ?>
                            </span>
                        <?php endforeach; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($member->getDeletedAt() !== null): ?>
                            <span class="badge bg-danger">Gelöscht</span>
                        <?php elseif (!$member->isActive()): ?>
                            <span class="badge bg-secondary">Inaktiv</span>
                        <?php elseif ($member->getPasswordHash() === null): ?>
                            <span class="badge bg-warning text-dark">Einladung offen</span>
                        <?php else: ?>
                            <span class="badge bg-success">Aktiv</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted">
                        <?= $member->getLastLoginAt() ? ViewHelper::formatDateTime($member->getLastLoginAt()) : '-' ?>
                    </td>
                    <td class="text-end">
                        <a href="<?= ViewHelper::url('/admin/users/' . $member->getId()) ?>" class="btn btn-sm btn-outline-secondary" title="Details">
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
