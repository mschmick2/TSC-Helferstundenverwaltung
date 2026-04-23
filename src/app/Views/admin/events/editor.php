<?php
/**
 * Modul 6 I7e-A Phase 1 — Stub-View fuer den non-modalen Editor
 * aus Admin-Sicht (Feature-Parity zur Organizer-Route). Wird in
 * Phase 2 durch die volle Sidebar- und Tree-Widget-Integration
 * ersetzt.
 *
 * Die acht Tree-Action-POST-Routen unter /admin/events/{id}/tasks/*
 * existieren bereits seit I7b1; dieser neue Editor-Einstieg ruft
 * sie in Phase 2 wieder auf.
 *
 * @var \App\Models\Event $event
 */
use App\Helpers\ViewHelper;
?>

<div class="alert alert-info" role="alert">
    <h1 class="h4">
        <i class="bi bi-tools" aria-hidden="true"></i>
        Admin-Editor (Phase-1-Stub)
    </h1>
    <p class="mb-1">
        Event: <strong><?= ViewHelper::e($event->getTitle()) ?></strong>
        (ID <?= (int) $event->getId() ?>)
    </p>
    <p class="mb-0 text-muted small">
        Phase 1 abgeschlossen — Feature-Parity-Route steht. Admins
        koennen ab Phase 2 denselben non-modalen Editor nutzen wie
        Organisatoren; der bisherige Modal-Editor unter
        /admin/events/{id}/edit bleibt unveraendert verfuegbar.
    </p>
</div>
