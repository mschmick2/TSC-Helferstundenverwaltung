<?php
/**
 * Report-Hauptseite mit Filtern, Zusammenfassung und Eintrags-Tabelle
 *
 * Variablen: $entries, $total, $summary, $categories, $members, $canFilterByMember,
 *            $filters, $page, $perPage, $user, $settings
 */

use App\Helpers\ViewHelper;
use App\Models\WorkEntry;

// Hilfsfunktion für sortierbare Spalten-Links
$sortLink = function (string $field, string $label, array $filters): string {
    $currentSort = $filters['sort'] ?? 'work_date';
    $currentDir = $filters['dir'] ?? 'DESC';

    $newDir = ($currentSort === $field && $currentDir === 'ASC') ? 'DESC' : 'ASC';

    $params = $filters;
    $params['sort'] = $field;
    $params['dir'] = $newDir;
    unset($params['page']);

    $icon = '';
    if ($currentSort === $field) {
        $icon = $currentDir === 'ASC'
            ? ' <i class="bi bi-sort-up"></i>'
            : ' <i class="bi bi-sort-down"></i>';
    }

    $query = http_build_query($params);
    return '<a href="' . ViewHelper::url('/reports') . '?' . htmlspecialchars($query, ENT_QUOTES, 'UTF-8') . '" class="text-decoration-none text-dark">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . $icon . '</a>';
};

// Export-URL mit aktuellen Filtern aufbauen
$exportUrl = function (string $format, array $filters): string {
    $params = $filters;
    unset($params['page']);
    $query = http_build_query($params);
    $url = ViewHelper::url('/reports/export/' . $format);
    if ($query !== '') {
        $url .= '?' . $query;
    }
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
};
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-bar-chart"></i> Reports
    </h1>
    <div>
        <a href="<?= $exportUrl('pdf', $filters) ?>" class="btn btn-outline-danger btn-sm me-1">
            <i class="bi bi-file-earmark-pdf"></i> PDF
        </a>
        <a href="<?= $exportUrl('csv', $filters) ?>" class="btn btn-outline-success btn-sm">
            <i class="bi bi-file-earmark-spreadsheet"></i> CSV
        </a>
    </div>
</div>

<!-- Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="<?= ViewHelper::url('/reports') ?>" class="row g-3">
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
                <label for="filter-status" class="form-label">Status</label>
                <select name="status" id="filter-status" class="form-select form-select-sm">
                    <option value="">Alle</option>
                    <?php foreach (WorkEntry::STATUS_LABELS as $key => $label): ?>
                        <option value="<?= $key ?>" <?= ($filters['status'] ?? '') === $key ? 'selected' : '' ?>>
                            <?= ViewHelper::e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label for="filter-category" class="form-label">Kategorie</label>
                <select name="category_id" id="filter-category" class="form-select form-select-sm">
                    <option value="">Alle</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat->getId() ?>" <?= (string) ($filters['category_id'] ?? '') === (string) $cat->getId() ? 'selected' : '' ?>>
                            <?= ViewHelper::e($cat->getName()) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($canFilterByMember): ?>
            <div class="col-md-2">
                <label for="filter-member" class="form-label">Mitglied</label>
                <select name="member_id" id="filter-member" class="form-select form-select-sm">
                    <option value="">Alle</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?= (int) $m['id'] ?>" <?= (string) ($filters['member_id'] ?? '') === (string) $m['id'] ? 'selected' : '' ?>>
                            <?= ViewHelper::e($m['vollname']) ?> (<?= ViewHelper::e($m['mitgliedsnummer']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-funnel"></i> Filtern
                </button>
                <a href="<?= ViewHelper::url('/reports') ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>

            <!-- Sortierung beibehalten -->
            <?php if (!empty($filters['sort']) && $filters['sort'] !== 'work_date'): ?>
                <input type="hidden" name="sort" value="<?= ViewHelper::e($filters['sort']) ?>">
            <?php endif; ?>
            <?php if (!empty($filters['dir']) && $filters['dir'] !== 'DESC'): ?>
                <input type="hidden" name="dir" value="<?= ViewHelper::e($filters['dir']) ?>">
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Zusammenfassung -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <h3 class="mb-0 text-primary"><?= ViewHelper::formatHours($summary['total_hours']) ?></h3>
                <small class="text-muted">Stunden gesamt</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body py-3">
                <h3 class="mb-0"><?= number_format($summary['entry_count'], 0, ',', '.') ?></h3>
                <small class="text-muted">Einträge</small>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-body py-3">
                <small class="text-muted d-block mb-1">Nach Status:</small>
                <?php if (!empty($summary['count_by_status'])): ?>
                    <?php foreach ($summary['count_by_status'] as $row): ?>
                        <span class="badge <?= WorkEntry::STATUS_BADGES[$row['status']] ?? 'bg-secondary' ?> me-1">
                            <?= ViewHelper::e(WorkEntry::STATUS_LABELS[$row['status']] ?? $row['status']) ?>: <?= $row['cnt'] ?>
                        </span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="text-muted">-</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Summen-Details (zusammenklappbar) -->
<?php if (!empty($summary['hours_by_category']) || !empty($summary['hours_by_member'])): ?>
<div class="accordion mb-4" id="summaryAccordion">
    <?php if (!empty($summary['hours_by_category'])): ?>
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed py-2" type="button"
                    data-bs-toggle="collapse" data-bs-target="#collapseCategories">
                <i class="bi bi-tag me-2"></i> Stunden pro Kategorie
            </button>
        </h2>
        <div id="collapseCategories" class="accordion-collapse collapse" data-bs-parent="#summaryAccordion">
            <div class="accordion-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Kategorie</th>
                            <th class="text-end" style="width: 120px;">Stunden</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary['hours_by_category'] as $row): ?>
                        <tr>
                            <td><?= ViewHelper::e($row['category_name']) ?></td>
                            <td class="text-end"><?= ViewHelper::formatHours($row['total_hours']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($summary['hours_by_member'])): ?>
    <div class="accordion-item">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed py-2" type="button"
                    data-bs-toggle="collapse" data-bs-target="#collapseMembers">
                <i class="bi bi-people me-2"></i> Stunden pro Mitglied
            </button>
        </h2>
        <div id="collapseMembers" class="accordion-collapse collapse" data-bs-parent="#summaryAccordion">
            <div class="accordion-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Mitglied</th>
                            <th>Mitgliedsnr.</th>
                            <th class="text-end" style="width: 120px;">Stunden</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary['hours_by_member'] as $row): ?>
                        <tr>
                            <td><?= ViewHelper::e($row['member_name']) ?></td>
                            <td><?= ViewHelper::e($row['mitgliedsnummer'] ?? '') ?></td>
                            <td class="text-end"><?= ViewHelper::formatHours($row['total_hours']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Eintrags-Tabelle -->
<?php if (empty($entries)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-inbox display-4"></i>
        <p class="mt-2">Keine Einträge für die gewählten Filter gefunden.</p>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th><?= $sortLink('entry_number', 'Nr.', $filters) ?></th>
                    <th><?= $sortLink('work_date', 'Datum', $filters) ?></th>
                    <?php if ($canFilterByMember): ?>
                        <th><?= $sortLink('user_name', 'Mitglied', $filters) ?></th>
                    <?php endif; ?>
                    <th><?= $sortLink('category_name', 'Kategorie', $filters) ?></th>
                    <th>Projekt</th>
                    <th class="text-end"><?= $sortLink('hours', 'Stunden', $filters) ?></th>
                    <th><?= $sortLink('status', 'Status', $filters) ?></th>
                    <th>Beschreibung</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry): ?>
                <tr>
                    <td>
                        <a href="<?= ViewHelper::url('/entries/' . (int) $entry['id']) ?>" class="text-decoration-none fw-semibold">
                            <?= ViewHelper::e($entry['entry_number'] ?? '') ?>
                        </a>
                    </td>
                    <td><?= ViewHelper::formatDate($entry['work_date'] ?? '') ?></td>
                    <?php if ($canFilterByMember): ?>
                        <td><?= ViewHelper::e($entry['user_name'] ?? '') ?></td>
                    <?php endif; ?>
                    <td><?= ViewHelper::e($entry['category_name'] ?? '-') ?></td>
                    <td><?= ViewHelper::e($entry['project'] ?? '-') ?></td>
                    <td class="text-end">
                        <?= ViewHelper::formatHours($entry['hours'] ?? 0) ?> h
                        <?php if (!empty($entry['is_corrected'])): ?>
                            <span class="badge bg-info text-dark" title="Korrigiert">
                                <i class="bi bi-pencil"></i>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= WorkEntry::STATUS_BADGES[$entry['status']] ?? 'bg-secondary' ?>">
                            <?= ViewHelper::e(WorkEntry::STATUS_LABELS[$entry['status']] ?? $entry['status'] ?? '') ?>
                        </span>
                        <?php if (!empty($entry['deleted_at'])): ?>
                            <span class="badge bg-dark" title="Gelöscht am <?= ViewHelper::formatDateTime($entry['deleted_at']) ?>">
                                <i class="bi bi-trash"></i>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-truncate" style="max-width: 200px;" title="<?= ViewHelper::e($entry['description'] ?? '') ?>">
                        <?= ViewHelper::e(mb_substr($entry['description'] ?? '', 0, 60)) ?>
                        <?= mb_strlen($entry['description'] ?? '') > 60 ? '...' : '' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php require __DIR__ . '/../components/_pagination.php'; ?>
<?php endif; ?>
