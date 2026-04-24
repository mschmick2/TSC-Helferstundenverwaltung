<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\EditSessionView;
use App\Repositories\EventOrganizerRepository;
use App\Repositories\EventRepository;
use App\Services\EditSessionService;
use App\Services\SettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * HTTP-Endpunkte fuer Edit-Session-Tracking (Modul 6 I7e-C.1 Phase 2).
 *
 * Vier Actions unter /api/edit-sessions/*:
 *   - POST   /start              startSession (201 Created)
 *   - POST   /{id:\d+}/heartbeat heartbeat   (200 | 404 | 410)
 *   - POST   /{id:\d+}/close     close       (200, idempotent)
 *   - GET    ?event_id=X         listForEvent (200)
 *
 * Auth + CSRF wird durch den Middleware-Stack auf der Route-Group
 * gewaehrleistet. Permission pro Action event-scoped: Admin-Rolle ODER
 * Organisator-Mitgliedschaft des Events (BaseController::canEditEvent).
 *
 * Architektur-Entscheidungen (siehe G1-Plan I7e-C):
 *   - close ignoriert den Feature-Flag bewusst (Service-Asymmetrie aus
 *     Phase 1) und antwortet idempotent 200 — der Client soll auch
 *     nach Feature-Abschaltung seine Session sauber aufraeumen koennen.
 *   - listForEvent liefert bei deaktiviertem Flag 200 mit leerer Liste,
 *     NICHT 410. So braucht der Client keinen separaten Fehler-Handler
 *     fuer die Polling-Route.
 *   - heartbeat liefert 404 bei allen Repo-false-Rueckgaben (Session
 *     fehlt, geschlossen, timeoutet, fremder User) — der Client
 *     reagiert auf 404 mit einem neuen Session-Start.
 */
class EditSessionController extends BaseController
{
    /**
     * Lehnt Session-IDs ab, die offensichtlich verdaechtig sind. Der
     * Kandidat-Block ist bewusst klein: der Service reicht durch, und
     * der IDOR-Filter auf Repo-Ebene haelt fremde Sessions ausserhalb.
     */
    private const MAX_BROWSER_SESSION_ID_LEN = 64;

    public function __construct(
        private readonly EditSessionService $editSessionService,
        private readonly EventRepository $eventRepo,
        private readonly EventOrganizerRepository $organizerRepo,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * POST /api/edit-sessions/start
     *
     * Body: { "event_id": int, "browser_session_id": string }.
     * Response 201: { "session_id": int }.
     */
    public function start(Request $request, Response $response): Response
    {
        if (!$this->settings->editSessionsEnabled()) {
            return $this->json($response, [
                'error' => 'feature_disabled',
            ], 410);
        }

        $user = $request->getAttribute('user');
        if ($user === null) {
            return $this->json($response, ['error' => 'unauthenticated'], 401);
        }

        $data = (array) $request->getParsedBody();
        $eventId = isset($data['event_id']) ? (int) $data['event_id'] : 0;
        $browserSessionId = isset($data['browser_session_id'])
            ? (string) $data['browser_session_id']
            : '';

        if ($eventId <= 0 || $browserSessionId === '') {
            return $this->json($response, [
                'error' => 'invalid_payload',
                'message' => 'event_id und browser_session_id sind Pflicht.',
            ], 400);
        }
        if (strlen($browserSessionId) > self::MAX_BROWSER_SESSION_ID_LEN) {
            return $this->json($response, [
                'error' => 'invalid_payload',
                'message' => 'browser_session_id ist zu lang (max. 64 Zeichen).',
            ], 400);
        }

        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            return $this->json($response, ['error' => 'event_not_found'], 404);
        }

        if (!$this->canEditEvent($user, $eventId, $this->organizerRepo)) {
            return $this->json($response, ['error' => 'forbidden'], 403);
        }

        try {
            $sessionId = $this->editSessionService->startSession(
                (int) $user->getId(),
                $eventId,
                $browserSessionId,
            );
        } catch (BusinessRuleException $e) {
            // Flag-Race: zwischen Feature-Flag-Check und Service-Call
            // wurde das Flag deaktiviert. Selten, aber moeglich.
            return $this->json($response, [
                'error' => 'feature_disabled',
                'message' => $e->getMessage(),
            ], 410);
        }

        return $this->json($response, ['session_id' => $sessionId], 201);
    }

    /**
     * POST /api/edit-sessions/{id}/heartbeat
     *
     * Response 200: { "ok": true }.
     * Response 404 bei allen Repo-false-Rueckgaben (Session fehlt,
     *              geschlossen, timeoutet, fremder User).
     * Response 410 bei deaktiviertem Feature-Flag.
     */
    public function heartbeat(Request $request, Response $response): Response
    {
        if (!$this->settings->editSessionsEnabled()) {
            return $this->json($response, ['error' => 'feature_disabled'], 410);
        }

        $user = $request->getAttribute('user');
        if ($user === null) {
            return $this->json($response, ['error' => 'unauthenticated'], 401);
        }

        $sessionId = (int) ($this->routeArgs($request)['id'] ?? 0);
        if ($sessionId <= 0) {
            return $this->json($response, ['error' => 'invalid_session_id'], 400);
        }

        $ok = $this->editSessionService->heartbeat($sessionId, (int) $user->getId());
        if (!$ok) {
            return $this->json($response, [
                'error' => 'session_not_found_or_expired',
            ], 404);
        }

        return $this->json($response, ['ok' => true]);
    }

    /**
     * POST /api/edit-sessions/{id}/close
     *
     * Idempotent: Doppel-Close aus dem beforeunload-Flow liefert
     * ebenfalls 200. Bei User-ID-Mismatch reicht der Service bool=false
     * durch — dem Client ist das egal (er wollte nur aufraeumen).
     *
     * Das Feature-Flag wird bewusst NICHT geprueft — auch nach Feature-
     * Abschaltung soll der Client seine laufende Session schliessen
     * koennen (Service-Asymmetrie aus Phase 1).
     */
    public function close(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return $this->json($response, ['error' => 'unauthenticated'], 401);
        }

        $sessionId = (int) ($this->routeArgs($request)['id'] ?? 0);
        if ($sessionId <= 0) {
            return $this->json($response, ['error' => 'invalid_session_id'], 400);
        }

        $this->editSessionService->close($sessionId, (int) $user->getId());
        return $this->json($response, ['ok' => true]);
    }

    /**
     * GET /api/edit-sessions?event_id=X
     *
     * Response 200: { "sessions": [ {...EditSessionView serialisiert...} ] }.
     * Bei deaktiviertem Flag: 200 mit leerem Array (kein 410, damit der
     * Client-Poller keinen Sonder-Fehlerpfad braucht).
     */
    public function listForEvent(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return $this->json($response, ['error' => 'unauthenticated'], 401);
        }

        $params = $request->getQueryParams();
        $eventId = isset($params['event_id']) ? (int) $params['event_id'] : 0;
        if ($eventId <= 0) {
            return $this->json($response, [
                'error' => 'invalid_event_id',
            ], 400);
        }

        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            return $this->json($response, ['error' => 'event_not_found'], 404);
        }

        if (!$this->canEditEvent($user, $eventId, $this->organizerRepo)) {
            return $this->json($response, ['error' => 'forbidden'], 403);
        }

        // listActiveForEvent respektiert das Feature-Flag und liefert bei
        // deaktiviertem Flag bereits eine leere Liste zurueck. Kein 410,
        // stattdessen 200 + []. Architect-Entscheidung aus G1.
        $sessions = $this->editSessionService->listActiveForEvent($eventId);

        return $this->json($response, [
            'sessions' => EditSessionView::toJsonReadyArray($sessions, (int) $user->getId()),
        ]);
    }
}
