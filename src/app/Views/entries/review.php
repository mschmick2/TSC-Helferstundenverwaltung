<?php
/**
 * Prüfer-Ansicht: Einträge zur Prüfung
 *
 * Variablen: $entries, $total, $page, $perPage, $categories, $filters, $user
 */

use App\Helpers\ViewHelper;
use App\Models\WorkEntry;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-clipboard-check"></i> Anträge prüfen
    </h1>
    <span class="badge bg-primary fs-6"><?= $total ?> Antrag/Anträge</span>
</div>

<!-- Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="<?= ViewHelper::url('/review') ?>" class="row g-3">
            <div class="col-md-2">
                <label for="filter-status" class="form-label">Status</label>
                <select name="status" id="filter-status" class="form-select form-select-sm">
                    <option value="">Eingereicht & In Klärung</option>
                    <option value="eingereicht" <?= ($filters['status'] ?? '') === 'eingereicht' ? 'selected' : '' ?>>
                        Eingereicht
                    </option>
                    <option value="in_klaerung" <?= ($filters['status'] ?? '') === 'in_klaerung' ? 'selected' : '' ?>>
                        In Klärung
                    </option>
                </select>
            </div>

            <div class="col-md-2">
                <label for="filter-category" class="form-label">Kategorie</label>
                <select name="category_id" id="filter-category" class="form-select form-select-sm">
                    <option value="">Alle</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat->getId() ?>" <?= (int) ($filters['category_id'] ?? 0) === $cat->getId() ? 'selected' : '' ?>>
                            <?= ViewHelper::e($cat->getName()) ?>
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

            <div class="col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-funnel"></i> Filtern
                </button>
                <a href="<?= ViewHelper::url('/review') ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Tabelle -->
<?php if (empty($entries)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-check-circle display-4"></i>
        <p class="mt-2">Keine Anträge zur Prüfung vorhanden.</p>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>Nr.</th>
                    <th>Mitglied</th>
                    <th>Datum</th>
                    <th>Kategorie</th>
                    <th>Stunden</th>
                    <th>Status</th>
                    <th>Eingereicht</th>
                    <th>Dialog</th>
                    <th class="text-end">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry): ?>
                <tr>
                    <td>
                        <a href="<?= ViewHelper::url('/entries/' . $entry->getId()) ?>" class="text-decoration-none fw-semibold">
                            <?= ViewHelper::e($entry->getEntryNumber()) ?>
                        </a>
                    </td>
                    <td><?= ViewHelper::e($entry->getUserName()) ?></td>
                    <td><?= ViewHelper::formatDate($entry->getWorkDate()) ?></td>
                    <td><?= ViewHelper::e($entry->getCategoryName() ?? '-') ?></td>
                    <td><?= ViewHelper::formatHours($entry->getHours()) ?> h</td>
                    <td>
                        <span class="badge <?= ViewHelper::e($entry->getStatusBadge()) ?>">
                            <?= ViewHelper::e($entry->getStatusLabel()) ?>
                        </span>
                    </td>
                    <td><?= ViewHelper::formatDateTime($entry->getSubmittedAt()) ?></td>
                    <td>
                        <?php if ($entry->getOpenQuestionsCount() > 0): ?>
                            <span class="badge bg-warning text-dark">
                                <i class="bi bi-chat-dots"></i> <?= $entry->getOpenQuestionsCount() ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a href="<?= ViewHelper::url('/entries/' . $entry->getId()) ?>" class="btn btn-sm btn-primary">
                            <i class="bi bi-eye"></i> Prüfen
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php require __DIR__ . '/../components/_pagination.php'; ?>
<?php endif; ?>
