<?php
/**
 * Soll-Stunden: Einzelziel bearbeiten
 *
 * Variablen: $user, $settings, $targetUser, $comparison, $year, $defaultTarget
 */

use App\Helpers\ViewHelper;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-bullseye"></i> Soll-Stunden: <?= ViewHelper::e($targetUser->getVollname()) ?>
    </h1>
    <a href="<?= ViewHelper::url('/admin/targets') ?>?year=<?= (int) $year ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
</div>

<div class="row g-4">
    <!-- Aktueller Stand -->
    <div class="col-md-5">
        <div class="card">
            <div class="card-header"><i class="bi bi-bar-chart"></i> Aktueller Stand <?= (int) $year ?></div>
            <div class="card-body">
                <?php
                $target = (float) $comparison['target'];
                $actual = (float) $comparison['actual'];
                $remaining = (float) $comparison['remaining'];
                $percentage = (float) $comparison['percentage'];
                $isExempt = (bool) $comparison['is_exempt'];
                $fulfilled = $actual >= $target;
                ?>

                <table class="table table-sm mb-3">
                    <tr>
                        <th>Mitgliedsnummer</th>
                        <td><?= ViewHelper::e($targetUser->getMitgliedsnummer()) ?></td>
                    </tr>
                    <tr>
                        <th>Soll-Stunden</th>
                        <td><?= number_format($target, 1, ',', '.') ?> Std.</td>
                    </tr>
                    <tr>
                        <th>Ist-Stunden</th>
                        <td><?= number_format($actual, 1, ',', '.') ?> Std.</td>
                    </tr>
                    <tr>
                        <th>Verbleibend</th>
                        <td>
                            <?php if ($isExempt): ?>
                                <span class="text-muted">Befreit</span>
                            <?php else: ?>
                                <?= number_format($remaining, 1, ',', '.') ?> Std.
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <!-- Fortschrittsbalken -->
                <?php if ($isExempt): ?>
                    <div class="progress" style="height: 25px;">
                        <div class="progress-bar bg-secondary" style="width: 100%;">Befreit</div>
                    </div>
                <?php else: ?>
                    <div class="progress" style="height: 25px;">
                        <div class="progress-bar <?= $fulfilled ? 'bg-success' : ($percentage >= 50 ? 'bg-warning' : 'bg-danger') ?>"
                             style="width: <?= round($percentage) ?>%;">
                            <?= round($percentage, 1) ?>%
                        </div>
                    </div>
                    <div class="text-center mt-1">
                        <?php if ($fulfilled): ?>
                            <span class="badge bg-success">Soll erfüllt</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Noch <?= number_format($remaining, 1, ',', '.') ?> Std. offen</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Ziel bearbeiten -->
    <div class="col-md-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-pencil"></i> Ziel bearbeiten</div>
            <div class="card-body">
                <form method="POST" action="<?= ViewHelper::url('/admin/targets/' . $targetUser->getId()) ?>">
                    <?= ViewHelper::csrfField() ?>
                    <input type="hidden" name="year" value="<?= (int) $year ?>">

                    <div class="mb-3">
                        <label for="target_hours" class="form-label">Soll-Stunden</label>
                        <div class="input-group">
                            <input type="number" name="target_hours" id="target_hours"
                                   class="form-control" step="0.5" min="0" max="9999"
                                   value="<?= number_format($target, 1, ',', '') ?>">
                            <span class="input-group-text">Stunden</span>
                        </div>
                        <div class="form-text">
                            Standard-Soll: <?= (int) $defaultTarget ?> Stunden.
                            Lassen Sie den Wert auf <?= (int) $defaultTarget ?>, um den Standard zu verwenden.
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input type="checkbox" name="is_exempt" value="1" id="is_exempt"
                                   class="form-check-input" <?= $isExempt ? 'checked' : '' ?>>
                            <label for="is_exempt" class="form-check-label">
                                Von Soll-Stunden befreit
                            </label>
                        </div>
                        <div class="form-text">
                            Befreite Mitglieder werden in der Übersicht als &bdquo;Befreit&ldquo; markiert und
                            nicht als &bdquo;nicht erfüllt&ldquo; gezählt.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notizen</label>
                        <textarea name="notes" id="notes" class="form-control" rows="3"
                                  placeholder="z.B. Grund für Befreiung oder abweichendes Soll"><?= ViewHelper::e($comparison['notes'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Speichern
                    </button>
                    <a href="<?= ViewHelper::url('/admin/targets') ?>?year=<?= (int) $year ?>" class="btn btn-outline-secondary">Abbrechen</a>
                </form>
            </div>
        </div>
    </div>
</div>
