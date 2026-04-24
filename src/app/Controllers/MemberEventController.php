<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessRuleException;
use App\Helpers\ViewHelper;
use App\Models\EventTask;
use App\Repositories\EventOrganizerRepository;
use App\Repositories\EventRepository;
use App\Repositories\EventTaskAssignmentRepository;
use App\Repositories\EventTaskRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\CalendarFeedService;
use App\Services\EventAssignmentService;
use App\Services\IcalService;
use App\Services\SettingsService;
use App\Services\TaskTreeAggregator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Mitglieder-Sicht auf Events (Modul 6 I2).
 *
 * Alle Routen unter /events + /my-events (Auth erforderlich, keine spezielle Rolle).
 */
class MemberEventController extends BaseController
{
    public function __construct(
        private EventRepository $eventRepo,
        private EventTaskRepository $taskRepo,
        private EventTaskAssignmentRepository $assignmentRepo,
        private EventOrganizerRepository $organizerRepo,
        private EventAssignmentService $assignmentService,
        private CalendarFeedService $calendarFeedService,
        private UserRepository $userRepo,
        private AuditService $auditService,
        private array $settings,
        private ?TaskTreeAggregator $treeAggregator = null,
        private ?SettingsService $settingsService = null
    ) {
    }

    // =========================================================================
    // GET /events  -- Liste veroeffentlichter Events
    // =========================================================================

    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $events = $this->eventRepo->findPublished();

        return $this->render($response, 'events/index', [
            'title' => 'Events',
            'user' => $user,
            'settings' => $this->settings,
            'events' => $events,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Events'],
            ],
        ]);
    }

    // =========================================================================
    // GET /events/{id}  -- Event-Detail mit Aufgaben-Liste
    // =========================================================================

    public function show(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];

        $event = $this->eventRepo->findById($id);
        if ($event === null || !$event->isPublished()) {
            ViewHelper::flash('danger', 'Event nicht verfuegbar.');
            return $this->redirect($response, '/events');
        }

        $tasks = $this->taskRepo->findByEvent($id);

        // Fuer jede Task: aktive Zusagen zaehlen + eigene Zusage des Users ermitteln
        $taskMeta = [];
        foreach ($tasks as $t) {
            $tid = (int) $t->getId();
            $taskMeta[$tid] = [
                'current_count' => $this->assignmentRepo->countActiveByTask($tid),
                'user_has_assignment' => $this->assignmentRepo->hasActiveAssignment($tid, (int) $user->getId()),
            ];
        }

        // Modul 6 I7b2 Phase 1: Baum-Aggregat fuer Accordion-Ansicht vorbereiten.
        // Nur aktiv, wenn events.tree_editor_enabled='1' UND das Event
        // tatsaechlich eine Baumstruktur hat (mindestens ein Gruppen- oder
        // Unter-Knoten). Andernfalls bleibt die bestehende flache Karten-
        // Liste die Single-Source.
        $treeEditorEnabled = false;
        $hasTreeStructure  = false;
        $treeData          = [];

        if ($this->settingsService !== null
            && $this->settingsService->getString('events.tree_editor_enabled', '0') === '1'
            && $this->treeAggregator !== null
        ) {
            $treeEditorEnabled = true;
            foreach ($tasks as $t) {
                if ($t->isGroup() || $t->getParentTaskId() !== null) {
                    $hasTreeStructure = true;
                    break;
                }
            }
            if ($hasTreeStructure) {
                $assignmentCounts = $this->assignmentRepo->countActiveByEvent($id);
                $treeData = $this->treeAggregator->buildTree($tasks, $assignmentCounts);
            }
        }

        return $this->render($response, 'events/show', [
            'title' => $event->getTitle(),
            'user' => $user,
            'settings' => $this->settings,
            'event' => $event,
            'tasks' => $tasks,
            'taskMeta' => $taskMeta,
            'treeEditorEnabled' => $treeEditorEnabled,
            'hasTreeStructure'  => $hasTreeStructure,
            'treeData'          => $treeData,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Events', 'url' => '/events'],
                ['label' => $event->getTitle()],
            ],
        ]);
    }

    // =========================================================================
    // POST /events/{eventId}/tasks/{taskId}/assign  -- Aufgabe uebernehmen
    // =========================================================================

    public function assign(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $args = $this->routeArgs($request);
        $eventId = (int) $args['eventId'];
        $taskId = (int) $args['taskId'];
        $data = (array) $request->getParsedBody();

        $proposedStart = trim((string) ($data['proposed_start'] ?? '')) ?: null;
        $proposedEnd   = trim((string) ($data['proposed_end']   ?? '')) ?: null;

        try {
            $this->assignmentService->assignMember(
                $taskId,
                (int) $user->getId(),
                $proposedStart,
                $proposedEnd
            );
            ViewHelper::flash('success', 'Aufgabe uebernommen.');
        } catch (BusinessRuleException $e) {
            ViewHelper::flash('danger', $e->getMessage());
        }

        return $this->redirect($response, '/events/' . $eventId);
    }

    // =========================================================================
    // GET /my-events  -- Eigene Zusagen
    // =========================================================================

    public function myAssignments(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userId = (int) $user->getId();
        $assignments = $this->assignmentRepo->findByUser($userId, activeOnly: false);

        // Kontext-Daten (Task + Event) vorladen
        $context = [];
        foreach ($assignments as $a) {
            $task = $this->taskRepo->findById($a->getTaskId());
            $event = $task !== null ? $this->eventRepo->findById($task->getEventId()) : null;
            $context[$a->getId()] = ['task' => $task, 'event' => $event];
        }

        // Kandidaten fuer Ersatz-Vorschlag im Storno-Dialog.
        // Aktive Mitglieder ohne System-User; aktueller User raus
        // (Self-Replacement wird serverseitig abgefangen).
        $replacementCandidates = array_values(array_filter(
            $this->userRepo->findAllActive(),
            static fn($u) => (int) $u->getId() !== $userId
        ));

        return $this->render($response, 'my-events/index', [
            'title' => 'Meine Zusagen',
            'user' => $user,
            'settings' => $this->settings,
            'assignments' => $assignments,
            'context' => $context,
            'replacementCandidates' => $replacementCandidates,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Meine Zusagen'],
            ],
        ]);
    }

    // =========================================================================
    // POST /my-events/assignments/{id}/withdraw  -- Unbestaetigte zurueckziehen
    // =========================================================================

    public function withdraw(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];

        try {
            $this->assignmentService->withdrawSelf($id, (int) $user->getId());
            ViewHelper::flash('success', 'Zusage zurueckgezogen.');
        } catch (AuthorizationException $e) {
            return $this->handleAuthorizationDenial(
                $e,
                $request,
                $response,
                '/my-events'
            );
        } catch (BusinessRuleException $e) {
            ViewHelper::flash('danger', $e->getMessage());
        }

        return $this->redirect($response, '/my-events');
    }

    // =========================================================================
    // POST /my-events/assignments/{id}/cancel  -- Storno-Anfrage
    // =========================================================================

    public function requestCancellation(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];
        $data = (array) $request->getParsedBody();

        $replacementRaw = trim((string) ($data['replacement_user_id'] ?? ''));
        $replacementId = $replacementRaw !== '' ? (int) $replacementRaw : null;
        $reason = trim((string) ($data['reason'] ?? '')) ?: null;

        try {
            $this->assignmentService->requestCancellation(
                $id,
                (int) $user->getId(),
                $replacementId,
                $reason
            );
            ViewHelper::flash('success', 'Storno-Anfrage gestellt. Organisator wird benachrichtigt.');
        } catch (AuthorizationException $e) {
            return $this->handleAuthorizationDenial(
                $e,
                $request,
                $response,
                '/my-events'
            );
        } catch (BusinessRuleException $e) {
            ViewHelper::flash('danger', $e->getMessage());
        }

        return $this->redirect($response, '/my-events');
    }

    // =========================================================================
    // I5: Kalender-Ansichten (/events/calendar + /my-events/calendar)
    // =========================================================================

    public function calendar(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        return $this->render($response, 'events/calendar', [
            'title' => 'Kalender',
            'user' => $user,
            'settings' => $this->settings,
            'feedUrl' => ViewHelper::url('/api/events/calendar'),
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Events', 'url' => '/events'],
                ['label' => 'Kalender'],
            ],
        ]);
    }

    public function myCalendar(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        return $this->render($response, 'my-events/calendar', [
            'title' => 'Mein Kalender',
            'user' => $user,
            'settings' => $this->settings,
            'feedUrl' => ViewHelper::url('/api/my-events/calendar'),
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Meine Events', 'url' => '/my-events'],
                ['label' => 'Kalender'],
            ],
        ]);
    }

    /**
     * JSON-Endpoint fuer FullCalendar (alle veroeffentlichten Events im Monat).
     */
    public function calendarJson(Request $request, Response $response): Response
    {
        [$from, $to] = $this->parseRange($request);
        $events = $this->eventRepo->findInRange($from, $to, false);

        $ids = array_map(fn($e) => (int) $e->getId(), $events);
        $colors = $this->eventRepo->findCategoryColorsByEventIds($ids);

        $basePath = $this->settings['app']['base_path'] ?? '';
        $feed = $this->calendarFeedService->buildEventsFeed($events, $colors, $basePath);

        return $this->jsonResponse($response, $feed);
    }

    /**
     * JSON-Endpoint fuer eigenen Kalender (nur Events mit eigenen Assignments).
     */
    public function myCalendarJson(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        [$from, $to] = $this->parseRange($request);

        $allInRange = $this->eventRepo->findInRange($from, $to, false);
        // Filter: nur Events, fuer die User aktives Assignment hat
        $events = array_values(array_filter(
            $allInRange,
            fn($e) => $this->userHasAssignmentOnEvent((int) $user->getId(), (int) $e->getId())
        ));

        $ids = array_map(fn($e) => (int) $e->getId(), $events);
        $colors = $this->eventRepo->findCategoryColorsByEventIds($ids);

        $basePath = $this->settings['app']['base_path'] ?? '';
        $feed = $this->calendarFeedService->buildMyAssignmentsFeed($events, $colors, $basePath);

        return $this->jsonResponse($response, $feed);
    }

    // =========================================================================
    // I5: iCal-Abo-Settings (in /my-events integriert)
    // =========================================================================

    public function icalSettings(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userId = (int) $user->getId();
        $token = $this->userRepo->getIcalToken($userId);

        // Lazy-Init: Token beim ersten Aufruf erzeugen
        if ($token === null) {
            $token = IcalService::generateToken();
            $this->userRepo->setIcalToken($userId, $token);
        }

        $basePath = $this->settings['app']['base_path'] ?? '';
        $appUrl   = rtrim($this->settings['app']['url'] ?? '', '/');
        // Defensiv: wenn app.url bereits den base_path enthaelt (wie oft auf Strato
        // konfiguriert: app.url=https://domain/helferstunden), nicht doppelt anhaengen.
        if ($basePath !== '' && !str_ends_with($appUrl, $basePath)) {
            $appUrl .= $basePath;
        }
        $subscribeUrl = $appUrl . '/ical/subscribe/' . $token;

        return $this->render($response, 'my-events/ical', [
            'title' => 'iCal-Abo',
            'user' => $user,
            'settings' => $this->settings,
            'subscribeUrl' => $subscribeUrl,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Meine Events', 'url' => '/my-events'],
                ['label' => 'iCal-Abo'],
            ],
        ]);
    }

    public function regenerateIcalToken(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userId = (int) $user->getId();
        $newToken = IcalService::generateToken();
        $this->userRepo->setIcalToken($userId, $newToken);

        $this->auditService->log(
            action: 'update',
            tableName: 'users',
            recordId: $userId,
            newValues: ['ical_token_rotated' => true],
            description: 'iCal-Subscribe-Token rotiert (alte Abo-URL ungueltig)',
        );

        ViewHelper::flash('success', 'Neuer iCal-Abo-Link erzeugt. Alter Link ist nicht mehr gueltig.');
        return $this->redirect($response, '/my-events/ical');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * FullCalendar sendet ?start=ISO8601&end=ISO8601 beim Range-Fetch.
     * Fallback: aktueller Monat +/- 15 Tage.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function parseRange(Request $request): array
    {
        $qp = $request->getQueryParams();
        $tz = new \DateTimeZone('Europe/Berlin');
        try {
            $from = new \DateTimeImmutable($qp['start'] ?? 'first day of this month -5 days', $tz);
            $to   = new \DateTimeImmutable($qp['end']   ?? 'last day of this month +5 days', $tz);
        } catch (\Throwable $e) {
            $from = new \DateTimeImmutable('first day of this month', $tz);
            $to   = new \DateTimeImmutable('last day of this month', $tz);
        }
        // Hart-Cap auf 120 Tage, damit der Endpoint nicht missbraucht wird
        if ($to->getTimestamp() - $from->getTimestamp() > 120 * 86400) {
            $to = $from->modify('+120 days');
        }
        return [$from, $to];
    }

    private function userHasAssignmentOnEvent(int $userId, int $eventId): bool
    {
        foreach ($this->taskRepo->findByEvent($eventId) as $task) {
            if ($this->assignmentRepo->hasActiveAssignment((int) $task->getId(), $userId)) {
                return true;
            }
        }
        return false;
    }

    private function jsonResponse(Response $response, array $data): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
