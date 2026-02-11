<?php
/**
 * CSV-Import-Formular
 *
 * Variablen: $user, $settings
 */

use App\Helpers\ViewHelper;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-upload"></i> CSV-Import
    </h1>
    <a href="<?= ViewHelper::url('/admin/users') ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurück
    </a>
</div>

<div class="row g-4">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">CSV-Datei hochladen</div>
            <div class="card-body">
                <form method="POST" action="<?= ViewHelper::url('/admin/users/import') ?>" enctype="multipart/form-data">
                    <?= ViewHelper::csrfField() ?>
                    <div class="mb-3">
                        <label for="csv-file" class="form-label">CSV-Datei auswählen</label>
                        <input type="file" name="csv_file" id="csv-file" class="form-control"
                               accept=".csv,.txt" required>
                        <div class="form-text">
                            Unterstützte Formate: CSV (Komma oder Semikolon getrennt). Zeichensatz: UTF-8 empfohlen, ISO-8859-1 wird automatisch konvertiert.
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload"></i> Import starten
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-5">
        <div class="card">
            <div class="card-header">CSV-Format</div>
            <div class="card-body">
                <p class="small text-muted">Die CSV-Datei muss folgende Spalten enthalten:</p>
                <table class="table table-sm small">
                    <thead>
                        <tr>
                            <th>Spalte</th>
                            <th>Pflicht</th>
                            <th>Beispiel</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>mitgliedsnummer</code></td>
                            <td><span class="badge bg-danger">Ja</span></td>
                            <td>M-001</td>
                        </tr>
                        <tr>
                            <td><code>nachname</code></td>
                            <td><span class="badge bg-danger">Ja</span></td>
                            <td>Müller</td>
                        </tr>
                        <tr>
                            <td><code>vorname</code></td>
                            <td><span class="badge bg-danger">Ja</span></td>
                            <td>Max</td>
                        </tr>
                        <tr>
                            <td><code>email</code></td>
                            <td><span class="badge bg-danger">Ja</span></td>
                            <td>max@example.com</td>
                        </tr>
                        <tr><td><code>strasse</code></td><td>Nein</td><td>Hauptstr. 1</td></tr>
                        <tr><td><code>plz</code></td><td>Nein</td><td>12345</td></tr>
                        <tr><td><code>ort</code></td><td>Nein</td><td>Berlin</td></tr>
                        <tr><td><code>telefon</code></td><td>Nein</td><td>0170-1234567</td></tr>
                        <tr><td><code>eintrittsdatum</code></td><td>Nein</td><td>2024-01-15</td></tr>
                    </tbody>
                </table>

                <div class="alert alert-info small mb-0">
                    <i class="bi bi-info-circle"></i>
                    <strong>Hinweis:</strong> Bei vorhandener Mitgliedsnummer werden die Stammdaten aktualisiert.
                    Neue Mitglieder erhalten automatisch eine Einladungs-E-Mail.
                </div>
            </div>
        </div>
    </div>
</div>
