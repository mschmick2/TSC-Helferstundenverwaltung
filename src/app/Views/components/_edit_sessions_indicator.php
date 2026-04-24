<?php

declare(strict_types=1);

/**
 * Partial: Anker fuer die Edit-Session-Anzeige (Modul 6 I7e-C.1 Phase 2).
 *
 * Rendert einen leeren Div-Container mit zwei Daten-Attributen:
 *   - data-event-id       : Event-ID fuer das Polling.
 *   - data-initial-sessions: serverseitig gerenderter Snapshot
 *                            ([]-Array bei deaktiviertem Flag oder
 *                            keinen fremden Editoren).
 *
 * Phase 2 liefert nur das DOM-Anker. Phase 3 bringt das JS, das
 * Polling startet, die Session als "aktiv" markiert und den Alert
 * rendert. Bei deaktiviertem Feature-Flag erzeugt der Controller
 * eine leere Liste -- der Alert wird dann nie gerendert.
 *
 * Erwartet im Render-Context:
 *   - $event           (\App\Models\Event)
 *   - $user            (\App\Models\User)
 *   - $initialSessions (\App\Models\EditSessionView[])  optional
 */

use App\Models\EditSessionView;

$__sessions    = $initialSessions ?? [];
$__viewerId    = ($user !== null) ? (int) $user->getId() : 0;
$__eventId     = ($event !== null) ? (int) $event->getId() : 0;
$__jsonReady   = EditSessionView::toJsonReadyArray($__sessions, $__viewerId);
$__jsonEncoded = json_encode($__jsonReady, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
?>
<div id="edit-sessions-indicator"
     class="edit-sessions-indicator"
     data-event-id="<?= $__eventId ?>"
     data-initial-sessions="<?= htmlspecialchars($__jsonEncoded, ENT_QUOTES, 'UTF-8') ?>">
</div>
