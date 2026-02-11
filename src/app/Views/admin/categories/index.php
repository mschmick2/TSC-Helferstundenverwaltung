<?php
/**
 * Kategorien-Verwaltung
 *
 * Variablen: $categories, $entryCounts, $user, $settings
 */

use App\Helpers\ViewHelper;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-tags"></i> Kategorien verwalten
    </h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
        <i class="bi bi-plus-lg"></i> Neue Kategorie
    </button>
</div>

<?php if (empty($categories)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-tags display-4"></i>
        <p class="mt-2">Keine Kategorien vorhanden.</p>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width: 60px;">Pos.</th>
                    <th>Name</th>
                    <th>Beschreibung</th>
                    <th class="text-center">Einträge</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $cat): ?>
                <tr class="<?= !$cat->isActive() ? 'table-secondary' : '' ?>">
                    <td><?= $cat->getSortOrder() ?></td>
                    <td class="fw-semibold"><?= ViewHelper::e($cat->getName()) ?></td>
                    <td class="text-muted small"><?= ViewHelper::e($cat->getDescription() ?? '-') ?></td>
                    <td class="text-center">
                        <span class="badge bg-light text-dark"><?= $entryCounts[$cat->getId()] ?? 0 ?></span>
                    </td>
                    <td class="text-center">
                        <?php if ($cat->isActive()): ?>
                            <span class="badge bg-success">Aktiv</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inaktiv</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <!-- Bearbeiten -->
                            <button type="button" class="btn btn-outline-primary" title="Bearbeiten"
                                    data-bs-toggle="modal" data-bs-target="#editModal-<?= $cat->getId() ?>">
                                <i class="bi bi-pencil"></i>
                            </button>

                            <!-- Aktivieren/Deaktivieren -->
                            <?php if ($cat->isActive()): ?>
                                <form method="POST" action="<?= ViewHelper::url('/admin/categories/' . $cat->getId() . '/deactivate') ?>" class="d-inline">
                                    <?= ViewHelper::csrfField() ?>
                                    <button type="submit" class="btn btn-outline-warning" title="Deaktivieren">
                                        <i class="bi bi-pause-circle"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="<?= ViewHelper::url('/admin/categories/' . $cat->getId() . '/activate') ?>" class="d-inline">
                                    <?= ViewHelper::csrfField() ?>
                                    <button type="submit" class="btn btn-outline-success" title="Aktivieren">
                                        <i class="bi bi-play-circle"></i>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <!-- Löschen -->
                            <?php if (($entryCounts[$cat->getId()] ?? 0) === 0): ?>
                                <button type="button" class="btn btn-outline-danger" title="Löschen"
                                        data-bs-toggle="modal" data-bs-target="#deleteModal-<?= $cat->getId() ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <!-- Edit Modal -->
                <div class="modal fade" id="editModal-<?= $cat->getId() ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="<?= ViewHelper::url('/admin/categories/' . $cat->getId()) ?>">
                                <?= ViewHelper::csrfField() ?>
                                <div class="modal-header">
                                    <h5 class="modal-title">Kategorie bearbeiten</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label for="edit-name-<?= $cat->getId() ?>" class="form-label">Name *</label>
                                        <input type="text" name="name" id="edit-name-<?= $cat->getId() ?>"
                                               class="form-control" maxlength="100" required
                                               value="<?= ViewHelper::e($cat->getName()) ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label for="edit-desc-<?= $cat->getId() ?>" class="form-label">Beschreibung</label>
                                        <textarea name="description" id="edit-desc-<?= $cat->getId() ?>"
                                                  class="form-control" rows="2" maxlength="500"><?= ViewHelper::e($cat->getDescription() ?? '') ?></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label for="edit-sort-<?= $cat->getId() ?>" class="form-label">Sortierung</label>
                                        <input type="number" name="sort_order" id="edit-sort-<?= $cat->getId() ?>"
                                               class="form-control" min="0"
                                               value="<?= $cat->getSortOrder() ?>">
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                                    <button type="submit" class="btn btn-primary">Speichern</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Delete Modal -->
                <?php if (($entryCounts[$cat->getId()] ?? 0) === 0): ?>
                <div class="modal fade" id="deleteModal-<?= $cat->getId() ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Kategorie löschen</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <p>Möchten Sie die Kategorie <strong><?= ViewHelper::e($cat->getName()) ?></strong> wirklich löschen?</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                                <form method="POST" action="<?= ViewHelper::url('/admin/categories/' . $cat->getId() . '/delete') ?>" class="d-inline">
                                    <?= ViewHelper::csrfField() ?>
                                    <button type="submit" class="btn btn-danger">Löschen</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= ViewHelper::url('/admin/categories') ?>">
                <?= ViewHelper::csrfField() ?>
                <div class="modal-header">
                    <h5 class="modal-title">Neue Kategorie</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="create-name" class="form-label">Name *</label>
                        <input type="text" name="name" id="create-name"
                               class="form-control" maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label for="create-desc" class="form-label">Beschreibung</label>
                        <textarea name="description" id="create-desc"
                                  class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="create-sort" class="form-label">Sortierung</label>
                        <input type="number" name="sort_order" id="create-sort"
                               class="form-control" min="0" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">Erstellen</button>
                </div>
            </form>
        </div>
    </div>
</div>
