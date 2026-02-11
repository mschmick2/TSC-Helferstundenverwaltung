<?php
/**
 * Import-Ergebnis
 *
 * Variablen: $result, $user, $settings
 */

use App\Helpers\ViewHelper;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-clipboard-check"></i> Import-Ergebnis
    </h1>
    <div>
        <a href="<?= ViewHelper::url('/admin/users/import') ?>" class="btn btn-outline-primary me-2">
            <i class="bi bi-upload"></i> Neuer Import
        </a>
        <a href="<?= ViewHelper::url('/admin/users') ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Zur Mitgliederliste
        </a>
    </div>
</div>

<!-- Zusammenfassung -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card text-center border-success">
            <div class="card-body">
                <h2 class="text-success"><?= $result['created'] ?></h2>
                <p class="mb-0 text-muted">Neu erstellt</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center border-info">
            <div class="card-body">
                <h2 class="text-info"><?= $result['updated'] ?></h2>
                <p class="mb-0 text-muted">Aktualisiert</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center border-danger">
            <div class="card-body">
                <h2 class="text-danger"><?= count($result['errors']) ?></h2>
                <p class="mb-0 text-muted">Fehler</p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center">
            <div class="card-body">
                <h2><?= $result['skipped'] ?></h2>
                <p class="mb-0 text-muted">Übersprungen</p>
            </div>
        </div>
    </div>
</div>

<!-- Fehler-Details -->
<?php if (!empty($result['errors'])): ?>
<div class="card border-danger">
    <div class="card-header text-danger">
        <i class="bi bi-exclamation-triangle"></i> Fehler-Details
    </div>
    <div class="card-body">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th style="width: 80px;">Zeile</th>
                    <th>Fehler</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($result['errors'] as $line => $error): ?>
                <tr>
                    <td class="fw-semibold"><?= $line ?></td>
                    <td><?= ViewHelper::e($error) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (empty($result['errors']) && $result['created'] === 0 && $result['updated'] === 0): ?>
<div class="alert alert-warning">
    <i class="bi bi-info-circle"></i> Die CSV-Datei enthielt keine importierbaren Daten.
</div>
<?php endif; ?>
