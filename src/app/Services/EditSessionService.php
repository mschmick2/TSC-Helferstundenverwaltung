<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\EditSessionView;
use App\Repositories\EditSessionRepository;

/**
 * Service fuer Edit-Session-Tracking (Modul 6 I7e-C.1 Phase 1).
 *
 * Duenne Orchestrierung zwischen Controller und Repository plus
 * Flag-Pruefung gegen SettingsService::editSessionsEnabled(). Keine
 * Business-Logik ueber die Flag-Gate-Pruefung hinaus — Session-
 * Tracking ist reine UX-Information, kein Workflow.
 *
 * Die Flag-Semantik ist asymmetrisch:
 *   - startSession / heartbeat / listActiveForEvent sind no-ops (bzw.
 *     werfen), wenn das Feature deaktiviert ist.
 *   - close funktioniert unabhaengig vom Flag. Falls der Admin das
 *     Feature mitten in einer laufenden Session abschaltet, soll der
 *     Client trotzdem seine Session sauber schliessen koennen.
 *
 * Hier schreibt der Service KEIN Audit-Log (Architect-C5 aus I7e-C G1:
 * Session-Events sind zu feingranular fuer das Audit-Log; die Tabelle
 * edit_sessions ist selbst der kurzlebige Record).
 */
final class EditSessionService
{
    public function __construct(
        private readonly EditSessionRepository $repo,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Startet eine neue Edit-Session. Wirft BusinessRuleException,
     * wenn das Feature deaktiviert ist — der Controller uebersetzt
     * das in einen 404 oder 403, je nach Kontext.
     */
    public function startSession(int $userId, int $eventId, string $browserSessionId): int
    {
        if (!$this->settings->editSessionsEnabled()) {
            throw new BusinessRuleException(
                'Edit-Session-Tracking ist nicht freigeschaltet.'
            );
        }
        return $this->repo->create($userId, $eventId, $browserSessionId);
    }

    /**
     * Heartbeat-Update. Liefert false bei deaktiviertem Flag oder
     * wenn die Session nicht zum User gehoert / geschlossen ist /
     * fehlt. Der Client kann den false-Return als Signal nutzen,
     * eine neue Session zu starten.
     */
    public function heartbeat(int $sessionId, int $userId): bool
    {
        if (!$this->settings->editSessionsEnabled()) {
            return false;
        }
        return $this->repo->heartbeat($sessionId, $userId);
    }

    /**
     * Session schliessen. Funktioniert auch bei deaktiviertem Flag,
     * damit bereits laufende Sessions bei Feature-Abschaltung sauber
     * aufraeumen koennen.
     */
    public function close(int $sessionId, int $userId): bool
    {
        return $this->repo->close($sessionId, $userId);
    }

    /**
     * Aktive Sessions fuer ein Event. Bei deaktiviertem Flag eine
     * leere Liste — der Controller rendert dann keine Anzeige.
     *
     * @return EditSessionView[]
     */
    public function listActiveForEvent(int $eventId): array
    {
        if (!$this->settings->editSessionsEnabled()) {
            return [];
        }
        return $this->repo->findActiveByEventId($eventId);
    }
}
