<?php
/**
 * @var \App\Models\EventTemplate[] $templates
 */
use App\Helpers\ViewHelper;
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-card-list"></i> Event-Templates</h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="collapse"
            data-bs-target="#newTemplateForm">
        <i class="bi bi-plus-circle"></i> Neues Template
    </button>
</div>

<div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    In <strong>I1</strong> nur Basis-CRUD fuer Templates. Task-Editor (Aufgaben-Katalog bearbeiten,
    als neue Version speichern) folgt in Increment <strong>I4</strong>.
</div>

<div class="collapse mb-3" id="newTemplateForm">
    <form method="POST" action="<?= ViewHelper::url('/admin/event-templates') ?>"
          class="card card-body row g-2">
        <?= ViewHelper::csrfField() ?>

        <div class="col-md-6">
            <label class="form-label">Name <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="name" maxlength="200" required>
        </div>

        <div class="col-md-6">
            <label class="form-label">Beschreibung</label>
            <input type="text" class="form-control" name="description">
        </div>

        <div class="col-md-12">
            <button type="submit" class="btn btn-primary">Anlegen</button>
        </div>
    </form>
</div>

<?php if (empty($templates)): ?>
    <p class="text-muted">Noch keine Templates.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Version</th>
                    <th>Beschreibung</th>
                    <th>Angelegt</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $t): ?>
                    <tr>
                        <td>
                            <a href="<?= ViewHelper::url('/admin/event-templates/' . (int) $t->getId()) ?>">
                                <?= ViewHelper::e($t->getName()) ?>
                            </a>
                        </td>
                        <td>v<?= (int) $t->getVersion() ?></td>
                        <td><?= ViewHelper::e($t->getDescription() ?? '-') ?></td>
                        <td><?= ViewHelper::formatDateTime($t->getCreatedAt()) ?></td>
                        <td class="text-end">
                            <form method="POST"
                                  action="<?= ViewHelper::url('/admin/event-templates/' . (int) $t->getId() . '/delete') ?>"
                                  class="d-inline"
                                  onsubmit="return confirm('Template wirklich loeschen?');">
                                <?= ViewHelper::csrfField() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
