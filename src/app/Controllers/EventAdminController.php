<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ViewHelper;
use App\Models\Event;
use App\Repositories\CategoryRepository;
use App\Repositories\EventOrganizerRepository;
use App\Repositories\EventRepository;
use App\Repositories\EventTaskRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller fuer Event-Verwaltung (I1: CRUD-Basics fuer Eventadmin).
 *
 * Zugriffsrollen: event_admin, administrator (geschuetzt via RoleMiddleware).
 * Die Mitglieder-facing Routen (Uebernahme, Storno etc.) kommen in I2.
 */
class EventAdminController extends BaseController
{
    public function __construct(
        private EventRepository $eventRepo,
        private EventTaskRepository $taskRepo,
        private EventOrganizerRepository $organizerRepo,
        private CategoryRepository $categoryRepo,
        private UserRepository $userRepo,
        private AuditService $auditService,
        private array $settings
    ) {
    }

    // =========================================================================
    // GET /admin/events  -- Liste
    // =========================================================================

    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();
        $statusFilter = isset($params['status']) ? (string) $params['status'] : null;

        $events = $this->eventRepo->findAllForAdmin($statusFilter);

        return $this->render($response, 'admin/events/index', [
            'title' => 'Events verwalten',
            'user' => $user,
            'settings' => $this->settings,
            'events' => $events,
            'statusFilter' => $statusFilter,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Verwaltung', 'url' => '/admin/users'],
                ['label' => 'Events'],
            ],
        ]);
    }

    // =========================================================================
    // GET /admin/events/create  -- Formular
    // =========================================================================

    public function create(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');

        return $this->render($response, 'admin/events/create', [
            'title' => 'Neues Event',
            'user' => $user,
            'settings' => $this->settings,
            'users' => $this->userRepo->findAllActive(),
            'categories' => $this->categoryRepo->findAllActive(),
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Events', 'url' => '/admin/events'],
                ['label' => 'Neues Event'],
            ],
        ]);
    }

    // =========================================================================
    // POST /admin/events  -- Anlegen
    // =========================================================================

    public function store(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        $title = trim((string) ($data['title'] ?? ''));
        $startAt = trim((string) ($data['start_at'] ?? ''));
        $endAt = trim((string) ($data['end_at'] ?? ''));
        $location = trim((string) ($data['location'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $deadlineHours = (int) ($data['cancel_deadline_hours'] ?? Event::DEFAULT_CANCEL_DEADLINE_HOURS);
        $organizerIds = array_map('intval', (array) ($data['organizer_ids'] ?? []));

        $error = $this->validateEventInput($title, $startAt, $endAt, $organizerIds);
        if ($error !== null) {
            ViewHelper::flash('danger', $error);
            return $this->redirect($response, '/admin/events/create');
        }

        $eventId = $this->eventRepo->create([
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'location' => $location !== '' ? $location : null,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'cancel_deadline_hours' => $deadlineHours,
            'created_by' => (int) $user->getId(),
        ]);

        foreach ($organizerIds as $uid) {
            $this->organizerRepo->assign($eventId, $uid, (int) $user->getId());
        }

        $this->auditService->log(
            action: 'create',
            tableName: 'events',
            recordId: $eventId,
            newValues: ['title' => $title, 'start_at' => $startAt, 'status' => Event::STATUS_ENTWURF],
            description: "Event angelegt: '$title'",
            metadata: ['organizer_ids' => $organizerIds]
        );

        ViewHelper::flash('success', 'Event angelegt.');
        return $this->redirect($response, '/admin/events/' . $eventId);
    }

    // =========================================================================
    // GET /admin/events/{id}  -- Detail
    // =========================================================================

    public function show(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];

        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            ViewHelper::flash('danger', 'Event nicht gefunden.');
            return $this->redirect($response, '/admin/events');
        }

        $tasks = $this->taskRepo->findByEvent($id);
        $organizers = $this->organizerRepo->listForEvent($id);

        return $this->render($response, 'admin/events/show', [
            'title' => 'Event: ' . $event->getTitle(),
            'user' => $user,
            'settings' => $this->settings,
            'event' => $event,
            'tasks' => $tasks,
            'organizers' => $organizers,
            'categories' => $this->categoryRepo->findAllActive(),
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Events', 'url' => '/admin/events'],
                ['label' => $event->getTitle()],
            ],
        ]);
    }

    // =========================================================================
    // GET /admin/events/{id}/edit  -- Formular
    // =========================================================================

    public function edit(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];

        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            ViewHelper::flash('danger', 'Event nicht gefunden.');
            return $this->redirect($response, '/admin/events');
        }

        return $this->render($response, 'admin/events/edit', [
            'title' => 'Event bearbeiten',
            'user' => $user,
            'settings' => $this->settings,
            'event' => $event,
            'users' => $this->userRepo->findAllActive(),
            'organizerIds' => $this->organizerRepo->listUserIdsForEvent($id),
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Events', 'url' => '/admin/events'],
                ['label' => $event->getTitle(), 'url' => '/admin/events/' . $id],
                ['label' => 'Bearbeiten'],
            ],
        ]);
    }

    // =========================================================================
    // POST /admin/events/{id}  -- Update
    // =========================================================================

    public function update(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];
        $data = (array) $request->getParsedBody();

        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            ViewHelper::flash('danger', 'Event nicht gefunden.');
            return $this->redirect($response, '/admin/events');
        }

        $oldRaw = $this->eventRepo->getRawById($id);

        $title = trim((string) ($data['title'] ?? ''));
        $startAt = trim((string) ($data['start_at'] ?? ''));
        $endAt = trim((string) ($data['end_at'] ?? ''));

        // Update darf Organizer-Count 0 akzeptieren (wird separat geprueft),
        // aber Titel/Start/End-Basics muessen stimmen.
        $error = $this->validateEventInput($title, $startAt, $endAt, null);
        if ($error !== null) {
            ViewHelper::flash('danger', $error);
            return $this->redirect($response, '/admin/events/' . $id . '/edit');
        }

        // Neuen State bauen (fuer Diff-Vergleich nach Update)
        $newState = [
            'title' => $title,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'location' => trim((string) ($data['location'] ?? '')) ?: null,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'cancel_deadline_hours' => (int) ($data['cancel_deadline_hours'] ?? Event::DEFAULT_CANCEL_DEADLINE_HOURS),
        ];

        $this->eventRepo->update($id, $newState);

        // Organizer-Sync
        $newOrganizerIds = array_map('intval', (array) ($data['organizer_ids'] ?? []));
        $oldOrganizerIds = $this->organizerRepo->listUserIdsForEvent($id);

        foreach (array_diff($newOrganizerIds, $oldOrganizerIds) as $addUid) {
            $this->organizerRepo->assign($id, (int) $addUid, (int) $user->getId());
        }
        foreach (array_diff($oldOrganizerIds, $newOrganizerIds) as $rmUid) {
            $this->organizerRepo->revoke($id, (int) $rmUid);
        }

        // Diff fuer Audit-Trail (Pattern aus rules/07-audit.md):
        // Nur geaenderte Felder in old/new_values, Rest weglassen.
        $diffOld = [];
        $diffNew = [];
        foreach ($newState as $field => $newValue) {
            $oldValue = $oldRaw[$field] ?? null;
            // Typ-tolerante Gleichheit (DB liefert Strings, Controller liefert gemischt)
            if ((string) ($oldValue ?? '') === (string) ($newValue ?? '')) {
                continue;
            }
            $diffOld[$field] = $oldValue;
            $diffNew[$field] = $newValue;
        }

        $this->auditService->log(
            action: 'update',
            tableName: 'events',
            recordId: $id,
            oldValues: $diffOld !== [] ? $diffOld : null,
            newValues: $diffNew !== [] ? $diffNew : null,
            description: 'Event aktualisiert',
            metadata: [
                'organizer_added' => array_values(array_diff($newOrganizerIds, $oldOrganizerIds)),
                'organizer_removed' => array_values(array_diff($oldOrganizerIds, $newOrganizerIds)),
                'fields_changed' => array_keys($diffNew),
            ]
        );

        ViewHelper::flash('success', 'Event aktualisiert.');
        return $this->redirect($response, '/admin/events/' . $id);
    }

    // =========================================================================
    // POST /admin/events/{id}/publish  -- Veroeffentlichen
    // =========================================================================

    public function publish(Request $request, Response $response): Response
    {
        $id = (int) $this->routeArgs($request)['id'];
        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            ViewHelper::flash('danger', 'Event nicht gefunden.');
            return $this->redirect($response, '/admin/events');
        }
        if ($event->getStatus() !== Event::STATUS_ENTWURF) {
            ViewHelper::flash('warning', 'Nur Entwuerfe koennen veroeffentlicht werden.');
            return $this->redirect($response, '/admin/events/' . $id);
        }

        $this->eventRepo->changeStatus($id, Event::STATUS_VEROEFFENTLICHT);

        $this->auditService->log(
            action: 'status_change',
            tableName: 'events',
            recordId: $id,
            oldValues: ['status' => Event::STATUS_ENTWURF],
            newValues: ['status' => Event::STATUS_VEROEFFENTLICHT],
            description: 'Event veroeffentlicht: ' . $event->getTitle()
        );

        ViewHelper::flash('success', 'Event veroeffentlicht.');
        return $this->redirect($response, '/admin/events/' . $id);
    }

    // =========================================================================
    // POST /admin/events/{id}/cancel  -- Absagen
    // =========================================================================

    public function cancel(Request $request, Response $response): Response
    {
        $id = (int) $this->routeArgs($request)['id'];
        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            ViewHelper::flash('danger', 'Event nicht gefunden.');
            return $this->redirect($response, '/admin/events');
        }

        $this->eventRepo->changeStatus($id, Event::STATUS_ABGESAGT);

        $this->auditService->log(
            action: 'status_change',
            tableName: 'events',
            recordId: $id,
            oldValues: ['status' => $event->getStatus()],
            newValues: ['status' => Event::STATUS_ABGESAGT],
            description: 'Event abgesagt: ' . $event->getTitle()
        );

        ViewHelper::flash('success', 'Event abgesagt.');
        return $this->redirect($response, '/admin/events/' . $id);
    }

    // =========================================================================
    // POST /admin/events/{id}/delete  -- Soft-Delete
    // =========================================================================

    public function delete(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];

        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            ViewHelper::flash('danger', 'Event nicht gefunden.');
            return $this->redirect($response, '/admin/events');
        }

        $this->eventRepo->softDelete($id, (int) $user->getId());

        $this->auditService->log(
            action: 'delete',
            tableName: 'events',
            recordId: $id,
            description: 'Event soft-deleted: ' . $event->getTitle()
        );

        ViewHelper::flash('success', 'Event geloescht.');
        return $this->redirect($response, '/admin/events');
    }

    // =========================================================================
    // Task-Handling (inline auf Event-Detail-Seite)
    // =========================================================================

    /**
     * POST /admin/events/{id}/tasks  -- Aufgabe/Beigabe hinzufuegen
     */
    public function addTask(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $eventId = (int) $this->routeArgs($request)['id'];
        $data = (array) $request->getParsedBody();

        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            ViewHelper::flash('danger', 'Event nicht gefunden.');
            return $this->redirect($response, '/admin/events');
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            ViewHelper::flash('danger', 'Task-Titel ist Pflicht.');
            return $this->redirect($response, '/admin/events/' . $eventId);
        }

        // ENUM-Inputs gegen Allowlist validieren (Defense-in-Depth;
        // DB-ENUM greift ebenfalls, aber hier besseres User-Feedback).
        $taskType = (string) ($data['task_type'] ?? \App\Models\EventTask::TYPE_AUFGABE);
        $slotMode = (string) ($data['slot_mode'] ?? \App\Models\EventTask::SLOT_FIX);
        $capacityMode = (string) ($data['capacity_mode'] ?? \App\Models\EventTask::CAP_UNBEGRENZT);

        if (!in_array($taskType, [\App\Models\EventTask::TYPE_AUFGABE, \App\Models\EventTask::TYPE_BEIGABE], true)
            || !in_array($slotMode, [\App\Models\EventTask::SLOT_FIX, \App\Models\EventTask::SLOT_VARIABEL], true)
            || !in_array($capacityMode, [
                \App\Models\EventTask::CAP_UNBEGRENZT,
                \App\Models\EventTask::CAP_ZIEL,
                \App\Models\EventTask::CAP_MAXIMUM,
            ], true)
        ) {
            ViewHelper::flash('danger', 'Ungueltige Auswahl bei Typ/Slot/Kapazitaet.');
            return $this->redirect($response, '/admin/events/' . $eventId);
        }

        $taskId = $this->taskRepo->create([
            'event_id' => $eventId,
            'category_id' => !empty($data['category_id']) ? (int) $data['category_id'] : null,
            'title' => $title,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'task_type' => $taskType,
            'slot_mode' => $slotMode,
            'start_at' => $slotMode === \App\Models\EventTask::SLOT_FIX ? ($data['task_start_at'] ?? null) : null,
            'end_at'   => $slotMode === \App\Models\EventTask::SLOT_FIX ? ($data['task_end_at']   ?? null) : null,
            'capacity_mode' => $capacityMode,
            'capacity_target' => !empty($data['capacity_target']) ? (int) $data['capacity_target'] : null,
            'hours_default' => (float) ($data['hours_default'] ?? 0.0),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        $this->auditService->log(
            action: 'create',
            tableName: 'event_tasks',
            recordId: $taskId,
            newValues: ['title' => $title, 'event_id' => $eventId],
            description: "Task '$title' zu Event #$eventId",
            metadata: ['task_type' => $data['task_type'] ?? 'aufgabe']
        );

        ViewHelper::flash('success', 'Aufgabe hinzugefuegt.');
        return $this->redirect($response, '/admin/events/' . $eventId);
    }

    /**
     * POST /admin/events/{eventId}/tasks/{taskId}/delete
     */
    public function deleteTask(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $eventId = (int) $this->routeArgs($request)['eventId'];
        $taskId = (int) $this->routeArgs($request)['taskId'];

        $task = $this->taskRepo->findById($taskId);
        if ($task === null || $task->getEventId() !== $eventId) {
            ViewHelper::flash('danger', 'Aufgabe nicht gefunden.');
            return $this->redirect($response, '/admin/events/' . $eventId);
        }

        $this->taskRepo->softDelete($taskId, (int) $user->getId());

        $this->auditService->log(
            action: 'delete',
            tableName: 'event_tasks',
            recordId: $taskId,
            description: 'Task soft-deleted: ' . $task->getTitle(),
            metadata: ['event_id' => $eventId]
        );

        ViewHelper::flash('success', 'Aufgabe geloescht.');
        return $this->redirect($response, '/admin/events/' . $eventId);
    }

    // =========================================================================
    // Private Helfer
    // =========================================================================

    /**
     * Gemeinsame Event-Input-Validierung fuer store() + update().
     *
     * @param list<int>|null $organizerIds  NULL = Organizer-Check ueberspringen
     *                                      (z.B. beim Update, wenn Organizer-
     *                                      Sync separat passiert)
     * @return ?string Fehler-Text oder NULL wenn OK
     */
    private function validateEventInput(
        string $title,
        string $startAt,
        string $endAt,
        ?array $organizerIds
    ): ?string {
        if ($title === '' || $startAt === '' || $endAt === '') {
            return 'Titel, Start und Ende sind Pflichtfelder.';
        }
        if (strtotime($endAt) <= strtotime($startAt)) {
            return 'Ende muss nach Start liegen.';
        }
        if ($organizerIds !== null && count($organizerIds) < 1) {
            return 'Mindestens ein Organisator muss ausgewaehlt sein.';
        }
        return null;
    }
}
