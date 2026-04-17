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
use App\Services\EventAssignmentService;
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
        private array $settings
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

        return $this->render($response, 'events/show', [
            'title' => $event->getTitle(),
            'user' => $user,
            'settings' => $this->settings,
            'event' => $event,
            'tasks' => $tasks,
            'taskMeta' => $taskMeta,
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
        $assignments = $this->assignmentRepo->findByUser((int) $user->getId(), activeOnly: false);

        // Kontext-Daten (Task + Event) vorladen
        $context = [];
        foreach ($assignments as $a) {
            $task = $this->taskRepo->findById($a->getTaskId());
            $event = $task !== null ? $this->eventRepo->findById($task->getEventId()) : null;
            $context[$a->getId()] = ['task' => $task, 'event' => $event];
        }

        return $this->render($response, 'my-events/index', [
            'title' => 'Meine Zusagen',
            'user' => $user,
            'settings' => $this->settings,
            'assignments' => $assignments,
            'context' => $context,
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
        } catch (AuthorizationException | BusinessRuleException $e) {
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
        } catch (AuthorizationException | BusinessRuleException $e) {
            ViewHelper::flash('danger', $e->getMessage());
        }

        return $this->redirect($response, '/my-events');
    }
}
