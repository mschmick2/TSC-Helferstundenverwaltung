<?php
/**
 * @var string $subscribeUrl
 */
use App\Helpers\ViewHelper;

$csrfToken = $_SESSION['csrf_token'] ?? '';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-link-45deg"></i> iCal-Abo</h1>
    <a href="<?= ViewHelper::url('/my-events') ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Zurueck
    </a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <p>
            Mit dieser URL kannst du deinen persoenlichen Helferstunden-Kalender
            in Outlook, Google Calendar, Apple Kalender oder Thunderbird abonnieren.
            Der Kalender wird von deinem Client alle paar Minuten aktualisiert.
        </p>

        <label class="form-label fw-bold" for="icalUrl">Deine Abo-URL:</label>
        <div class="input-group mb-3">
            <input id="icalUrl" type="text" class="form-control font-monospace small"
                   value="<?= ViewHelper::e($subscribeUrl) ?>" readonly
                   onclick="this.select()">
            <button class="btn btn-outline-primary" type="button"
                    onclick="navigator.clipboard.writeText(document.getElementById('icalUrl').value); this.innerHTML='<i class=&quot;bi bi-check&quot;></i> kopiert';">
                <i class="bi bi-clipboard"></i> Kopieren
            </button>
        </div>

        <div class="alert alert-warning small mb-0">
            <i class="bi bi-shield-exclamation"></i>
            <strong>Vertraulich:</strong> Die URL enthaelt einen Token, der Lesezugriff
            auf alle deine Event-Teilnahmen gewaehrt. Gib sie nicht weiter.
            Falls der Token kompromittiert ist, erzeuge unten einen neuen Link.
        </div>
    </div>
</div>

<div class="card border-danger">
    <div class="card-body">
        <h2 class="h6"><i class="bi bi-arrow-repeat"></i> Neuen Abo-Link erzeugen</h2>
        <p class="small text-muted mb-2">
            Der alte Link wird sofort ungueltig. Du musst das Abo in allen Kalender-Clients
            mit der neuen URL neu einrichten.
        </p>
        <form method="POST" action="<?= ViewHelper::url('/my-events/ical/regenerate') ?>"
              onsubmit="return confirm('Alten Abo-Link wirklich ungueltig machen?');">
            <input type="hidden" name="csrf_token" value="<?= ViewHelper::e($csrfToken) ?>">
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-arrow-repeat"></i> Neuen Token erzeugen
            </button>
        </form>
    </div>
</div>
