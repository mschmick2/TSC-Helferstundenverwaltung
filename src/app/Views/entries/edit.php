<?php
/**
 * Arbeitsstunden-Eintrag bearbeiten
 *
 * Variablen: $entry, $categories, $user, $fieldConfig
 */

use App\Helpers\ViewHelper;

$fc = $fieldConfig ?? [];
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">
                <i class="bi bi-pencil"></i> Eintrag bearbeiten
                <small class="text-muted"><?= ViewHelper::e($entry->getEntryNumber()) ?></small>
            </h1>
            <a href="<?= ViewHelper::url('/entries/' . $entry->getId()) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Zurück
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST" action="<?= ViewHelper::url('/entries/' . $entry->getId()) ?>">
                    <?= ViewHelper::csrfField() ?>
                    <input type="hidden" name="version" value="<?= $entry->getVersion() ?>">

                    <!-- Datum -->
                    <?php if (($fc['work_date'] ?? 'required') !== 'hidden'): ?>
                    <div class="mb-3">
                        <label for="work_date" class="form-label">
                            Datum <?= ($fc['work_date'] ?? 'required') === 'required' ? '<span class="text-danger">*</span>' : '' ?>
                        </label>
                        <input type="date" name="work_date" id="work_date"
                               class="form-control"
                               value="<?= ViewHelper::old('work_date', $entry->getWorkDate()) ?>"
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
                                   value="<?= ViewHelper::old('time_from', $entry->getTimeFrom() ?? '') ?>"
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
                                   value="<?= ViewHelper::old('time_to', $entry->getTimeTo() ?? '') ?>"
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
                               value="<?= ViewHelper::old('hours', (string) $entry->getHours()) ?>"
                               <?= ($fc['hours'] ?? 'required') === 'required' ? 'required' : '' ?>>
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
                            <?php
                            $selectedCat = ViewHelper::old('category_id', (string) $entry->getCategoryId());
                            foreach ($categories as $cat): ?>
                                <option value="<?= $cat->getId() ?>"
                                    <?= $selectedCat == $cat->getId() ? 'selected' : '' ?>>
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
                               value="<?= ViewHelper::old('project', $entry->getProject() ?? '') ?>"
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
                                  <?= ($fc['description'] ?? 'optional') === 'required' ? 'required' : '' ?>><?= ViewHelper::old('description', $entry->getDescription() ?? '') ?></textarea>
                    </div>
                    <?php endif; ?>

                    <!-- Buttons -->
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-secondary">
                            <i class="bi bi-save"></i> Speichern
                        </button>
                        <button type="submit" name="submit_immediately" value="1" class="btn btn-primary">
                            <i class="bi bi-send"></i> Speichern & Einreichen
                        </button>
                        <a href="<?= ViewHelper::url('/entries/' . $entry->getId()) ?>" class="btn btn-outline-secondary ms-auto">
                            Abbrechen
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
