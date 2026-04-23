<?php
/**
 * Modul 6 I7e-A Phase 1 — Stub-View fuer den non-modalen
 * Organisator-Editor. Wird in Phase 2 durch die volle Sidebar-
 * und Tree-Widget-Integration ersetzt.
 *
 * Die Route funktioniert seit Phase 1 auf Basis von
 * OrganizerEventEditController::showEditor (isOrganizer-Gate +
 * Flag-Check). Alle acht Tree-Action-Endpoints sind ebenfalls
 * bereits registriert und testbar via curl.
 *
 * @var \App\Models\Event $event
 */
use App\Helpers\ViewHelper;
?>

<div class="alert alert-info" role="alert">
    <h1 class="h4">
        <i class="bi bi-tools" aria-hidden="true"></i>
        Organisator-Editor (Phase-1-Stub)
    </h1>
    <p class="mb-1">
        Event: <strong><?= ViewHelper::e($event->getTitle()) ?></strong>
        (ID <?= (int) $event->getId() ?>)
    </p>
    <p class="mb-0 text-muted small">
        Phase 1 abgeschlossen — Controller, Routen und Authorization
        stehen. Die Editor-UI mit Sidebar + Tree-Widget kommt in Phase 2
        (Modul 6 I7e-A).
    </p>
</div>
