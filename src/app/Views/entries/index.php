<?php
/**
 * Eigene Arbeitsstunden - Übersicht
 *
 * Variablen: $entries, $total, $page, $perPage, $categories, $filters, $user
 */

use App\Helpers\ViewHelper;
use App\Models\WorkEntry;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-list-check"></i> Meine Arbeitsstunden
    </h1>
    <a href="<?= ViewHelper::url('/entries/create') ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> Neuer Eintrag
    </a>
</div>

<!-- Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="<?= ViewHelper::url('/entries') ?>" class="row g-3">
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
                <a href="<?= ViewHelper::url('/entries') ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Tabelle -->
<?php if (empty($entries)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-inbox display-4"></i>
        <p class="mt-2">Keine Einträge gefunden.</p>
        <a href="<?= ViewHelper::url('/entries/create') ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Ersten Eintrag erstellen
        </a>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>Nr.</th>
                    <th>Datum</th>
                    <th>Kategorie</th>
                    <th>Stunden</th>
                    <th>Status</th>
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
                    <td><?= ViewHelper::formatDate($entry->getWorkDate()) ?></td>
                    <td><?= ViewHelper::e($entry->getCategoryName() ?? '-') ?></td>
                    <td>
                        <?= ViewHelper::formatHours($entry->getHours()) ?> h
                        <?php if ($entry->isCorrected()): ?>
                            <span class="badge bg-info text-dark" title="Korrigiert von <?= ViewHelper::formatHours($entry->getOriginalHours()) ?> h">
                                <i class="bi bi-pencil"></i>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= ViewHelper::e($entry->getStatusBadge()) ?>">
                            <?= ViewHelper::e($entry->getStatusLabel()) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($entry->getOpenQuestionsCount() > 0): ?>
                            <span class="badge bg-warning text-dark" title="Offene Rückfragen">
                                <i class="bi bi-chat-dots"></i> <?= $entry->getOpenQuestionsCount() ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="<?= ViewHelper::url('/entries/' . $entry->getId()) ?>" class="btn btn-outline-secondary" title="Details">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if ($entry->isEditable()): ?>
                                <a href="<?= ViewHelper::url('/entries/' . $entry->getId() . '/edit') ?>" class="btn btn-outline-primary" title="Bearbeiten">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            <?php endif; ?>
                            <?php if ($entry->isSubmittable()): ?>
                                <form method="POST" action="<?= ViewHelper::url('/entries/' . $entry->getId() . '/submit') ?>" class="d-inline">
                                    <?= ViewHelper::csrfField() ?>
                                    <button type="submit" class="btn btn-outline-success" title="Einreichen">
                                        <i class="bi bi-send"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php require __DIR__ . '/../components/_pagination.php'; ?>
<?php endif; ?>
