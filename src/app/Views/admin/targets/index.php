<?php
/**
 * Soll-Stunden Übersicht
 *
 * Variablen: $user, $settings, $enabled, $comparisons, $year, $defaultTarget, $onlyUnfulfilled
 */

use App\Helpers\ViewHelper;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-bullseye"></i> Soll-Stunden <?= (int) $year ?>
    </h1>
</div>

<?php if (!$enabled): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle"></i>
        <strong>Soll-Stunden-Funktion ist deaktiviert.</strong>
        Aktivieren Sie diese unter <a href="<?= ViewHelper::url('/admin/settings') ?>">Einstellungen</a> (Abschnitt &bdquo;Soll-Stunden&ldquo;).
    </div>
<?php else: ?>

<!-- Filter-Leiste -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="<?= ViewHelper::url('/admin/targets') ?>" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="year" class="form-label">Jahr</label>
                <select name="year" id="year" class="form-select">
                    <?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 5; $y--): ?>
                        <option value="<?= $y ?>" <?= $y === (int) $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <div class="form-check mt-4">
                    <input type="checkbox" name="unfulfilled" value="1" id="unfulfilled"
                           class="form-check-input" <?= $onlyUnfulfilled ? 'checked' : '' ?>>
                    <label for="unfulfilled" class="form-check-label">Nur nicht erfüllt</label>
                </div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel"></i> Filtern
                </button>
            </div>
            <div class="col-md-3 text-end">
                <span class="text-muted small">Standard-Soll: <strong><?= (int) $defaultTarget ?> Std.</strong></span>
            </div>
        </form>
    </div>
</div>

<!-- Übersichtstabelle -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Mitgliedsnr.</th>
                        <th>Name</th>
                        <th class="text-center">Soll</th>
                        <th class="text-center">Ist</th>
                        <th class="text-center">Verbleibend</th>
                        <th style="min-width: 150px;">Fortschritt</th>
                        <th class="text-center">Status</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($comparisons)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                <?= $onlyUnfulfilled ? 'Alle Mitglieder haben ihr Soll erfüllt.' : 'Keine Mitglieder gefunden.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($comparisons as $row): ?>
                            <?php
                            $target = (float) $row['target_hours'];
                            $actual = (float) $row['actual_hours'];
                            $isExempt = (bool) $row['is_exempt'];
                            $remaining = max(0, $target - $actual);
                            $percentage = $target > 0 ? min(100, ($actual / $target) * 100) : 100;
                            $fulfilled = $actual >= $target;
                            ?>
                            <tr>
                                <td><?= ViewHelper::e($row['mitgliedsnummer'] ?? '') ?></td>
                                <td><?= ViewHelper::e($row['user_name'] ?? '') ?></td>
                                <td class="text-center"><?= number_format($target, 1, ',', '.') ?></td>
                                <td class="text-center"><?= number_format($actual, 1, ',', '.') ?></td>
                                <td class="text-center">
                                    <?php if ($isExempt): ?>
                                        <span class="text-muted">-</span>
                                    <?php else: ?>
                                        <?= number_format($remaining, 1, ',', '.') ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isExempt): ?>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar bg-secondary" style="width: 100%;">Befreit</div>
                                        </div>
                                    <?php else: ?>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar <?= $fulfilled ? 'bg-success' : ($percentage >= 50 ? 'bg-warning' : 'bg-danger') ?>"
                                                 style="width: <?= round($percentage) ?>%;">
                                                <?= round($percentage) ?>%
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($isExempt): ?>
                                        <span class="badge bg-secondary">Befreit</span>
                                    <?php elseif ($fulfilled): ?>
                                        <span class="badge bg-success">Erfüllt</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Offen</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= ViewHelper::url('/admin/targets/' . (int) $row['user_id']) ?>?year=<?= (int) $year ?>"
                                       class="btn btn-sm btn-outline-primary" title="Bearbeiten">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Zusammenfassung -->
<?php if (!empty($comparisons)): ?>
    <?php
    $totalMembers = count($comparisons);
    $exemptCount = count(array_filter($comparisons, fn($r) => (bool) $r['is_exempt']));
    $fulfilledCount = count(array_filter($comparisons, fn($r) => !((bool) $r['is_exempt']) && (float) $r['actual_hours'] >= (float) $r['target_hours']));
    $openCount = $totalMembers - $exemptCount - $fulfilledCount;
    ?>
    <div class="row g-3 mt-3">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body py-2">
                    <div class="text-muted small">Gesamt</div>
                    <div class="fw-bold fs-5"><?= $totalMembers ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-success">
                <div class="card-body py-2">
                    <div class="text-muted small">Erfüllt</div>
                    <div class="fw-bold fs-5 text-success"><?= $fulfilledCount ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-danger">
                <div class="card-body py-2">
                    <div class="text-muted small">Offen</div>
                    <div class="fw-bold fs-5 text-danger"><?= $openCount ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-secondary">
                <div class="card-body py-2">
                    <div class="text-muted small">Befreit</div>
                    <div class="fw-bold fs-5 text-secondary"><?= $exemptCount ?></div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php endif; ?>
