<?php
/**
 * Neuen Arbeitsstunden-Eintrag erstellen
 *
 * Variablen: $categories, $user, $fieldConfig
 */

use App\Helpers\ViewHelper;

$fc = $fieldConfig ?? [];
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">
                <i class="bi bi-plus-circle"></i> Neue Arbeitsstunden erfassen
            </h1>
            <a href="<?= ViewHelper::url('/entries') ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Zurück
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST" action="<?= ViewHelper::url('/entries') ?>">
                    <?= ViewHelper::csrfField() ?>

                    <!-- Datum -->
                    <?php if (($fc['work_date'] ?? 'required') !== 'hidden'): ?>
                    <div class="mb-3">
                        <label for="work_date" class="form-label">
                            Datum <?= ($fc['work_date'] ?? 'required') === 'required' ? '<span class="text-danger">*</span>' : '' ?>
                        </label>
                        <input type="date" name="work_date" id="work_date"
                               class="form-control"
                               value="<?= ViewHelper::old('work_date', date('Y-m-d')) ?>"
                               <?= ($fc['work_date'] ?? 'required') === 'required' ? 'required' : '' ?>>
                    </div>
                    <?php endif; ?>

                    <!-- Uhrzeit Von/Bis -->
                    <?php if (($fc['time_from'] ?? 'optional') !== 'hidden' || ($fc['time_to'] ?? 'optional') !== 'hidden'): ?>
                    <div class="row mb-3">
                        <?php if (($fc['time_from'] ?? 'optional') !== 'hidden'): ?>
                        <div class="col-md-6">
                            <label for="time_from" class="form-label">
                                Von (Uhrzeit) <?= ($fc['time_from'] ?? 'optional') === 'required' ? '<span class="text-danger">*</span>' : '' ?>
                            </label>
                            <input type="time" name="time_from" id="time_from"
                                   class="form-control"
                                   value="<?= ViewHelper::old('time_from') ?>"
                                   <?= ($fc['time_from'] ?? 'optional') === 'required' ? 'required' : '' ?>>
                        </div>
                        <?php endif; ?>
                        <?php if (($fc['time_to'] ?? 'optional') !== 'hidden'): ?>
                        <div class="col-md-6">
                            <label for="time_to" class="form-label">
                                Bis (Uhrzeit) <?= ($fc['time_to'] ?? 'optional') === 'required' ? '<span class="text-danger">*</span>' : '' ?>
                            </label>
                            <input type="time" name="time_to" id="time_to"
                                   class="form-control"
                                   value="<?= ViewHelper::old('time_to') ?>"
                                   <?= ($fc['time_to'] ?? 'optional') === 'required' ? 'required' : '' ?>>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Stunden -->
                    <?php if (($fc['hours'] ?? 'required') !== 'hidden'): ?>
                    <div class="mb-3">
                        <label for="hours" class="form-label">
                            Stunden <?= ($fc['hours'] ?? 'required') === 'required' ? '<span class="text-danger">*</span>' : '' ?>
                        </label>
                        <input type="number" name="hours" id="hours"
                               class="form-control" step="0.25" min="0.25" max="24"
                               value="<?= ViewHelper::old('hours') ?>"
                               <?= ($fc['hours'] ?? 'required') === 'required' ? 'required' : '' ?>>
                        <div class="form-text">Dezimalzahl, z.B. 2,5 für zweieinhalb Stunden</div>
                    </div>
                    <?php endif; ?>

                    <!-- Kategorie -->
                    <?php if (($fc['category_id'] ?? 'required') !== 'hidden'): ?>
                    <div class="mb-3">
                        <label for="category_id" class="form-label">
                            Kategorie <?= ($fc['category_id'] ?? 'required') === 'required' ? '<span class="text-danger">*</span>' : '' ?>
                        </label>
                        <select name="category_id" id="category_id" class="form-select"
                                <?= ($fc['category_id'] ?? 'required') === 'required' ? 'required' : '' ?>>
                            <option value="">-- Bitte wählen --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat->getId() ?>"
                                    <?= ViewHelper::old('category_id') == $cat->getId() ? 'selected' : '' ?>>
                                    <?= ViewHelper::e($cat->getName()) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- Projekt -->
                    <?php if (($fc['project'] ?? 'optional') !== 'hidden'): ?>
                    <div class="mb-3">
                        <label for="project" class="form-label">
                            Projekt/Veranstaltung <?= ($fc['project'] ?? 'optional') === 'required' ? '<span class="text-danger">*</span>' : '' ?>
                        </label>
                        <input type="text" name="project" id="project"
                               class="form-control" maxlength="255"
                               value="<?= ViewHelper::old('project') ?>"
                               placeholder="z.B. Sommerfest, Platzpflege"
                               <?= ($fc['project'] ?? 'optional') === 'required' ? 'required' : '' ?>>
                    </div>
                    <?php endif; ?>

                    <!-- Beschreibung -->
                    <?php if (($fc['description'] ?? 'optional') !== 'hidden'): ?>
                    <div class="mb-3">
                        <label for="description" class="form-label">
                            Beschreibung <?= ($fc['description'] ?? 'optional') === 'required' ? '<span class="text-danger">*</span>' : '' ?>
                        </label>
                        <textarea name="description" id="description"
                                  class="form-control" rows="3"
                                  placeholder="Was haben Sie gemacht?"
                                  <?= ($fc['description'] ?? 'optional') === 'required' ? 'required' : '' ?>><?= ViewHelper::old('description') ?></textarea>
                    </div>
                    <?php endif; ?>

                    <!-- Buttons -->
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-secondary">
                            <i class="bi bi-save"></i> Als Entwurf speichern
                        </button>
                        <button type="submit" name="submit_immediately" value="1" class="btn btn-primary">
                            <i class="bi bi-send"></i> Speichern & Einreichen
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
