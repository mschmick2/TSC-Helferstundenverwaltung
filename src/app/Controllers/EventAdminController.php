<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Helpers\ViewHelper;
use App\Models\Event;
use App\Models\EventTask;
use App\Models\TaskStatus;
use App\Repositories\CategoryRepository;
use App\Repositories\EventOrganizerRepository;
use App\Repositories\EventRepository;
use App\Repositories\EventTaskAssignmentRepository;
use App\Repositories\EventTaskRepository;
use App\Repositories\EventTemplateRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\EventCompletionService;
use App\Services\SchedulerService;
use App\Services\SettingsService;
use App\Services\TaskTreeAggregator;
use App\Services\TaskTreeService;
use DateInterval;
use DateTimeImmutable;
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
        private EventCompletionService $completionService,
        private EventTemplateRepository $templateRepo,
        private array $settings,
        private ?SchedulerService $scheduler = null,
        private ?TaskTreeService $treeService = null,
        private ?TaskTreeAggregator $treeAggregator = null,
        private ?EventTaskAssignmentRepository $assignmentRepo = null,
        private ?SettingsService $settingsService = null
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
        $sourceTemplate = null;
        if ($event->isDerivedFromTemplate()) {
            $sourceTemplate = $this->templateRepo->findById((int) $event->getSourceTemplateId());
        }

        // Modul 6 I7b1 Phase 3c: Read-Only-Baumansicht anbieten, sobald das
        // Feature-Flag an ist UND das Event mindestens einen Gruppen- oder
        // Unter-Knoten besitzt. Andernfalls bleibt die bestehende flache
        // Tabelle in der View greifen.
        $treeEditorEnabled = false;
        $hasTreeStructure  = false;
        $treeData          = [];

        if ($this->settingsService !== null
            && $this->settingsService->getString('events.tree_editor_enabled', '0') === '1'
            && $this->assignmentRepo !== null
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

        return $this->render($response, 'admin/events/show', [
            'title' => 'Event: ' . $event->getTitle(),
            'user' => $user,
            'settings' => $this->settings,
            'event' => $event,
            'tasks' => $tasks,
            'organizers' => $organizers,
            'categories' => $this->categoryRepo->findAllActive(),
            'sourceTemplate' => $sourceTemplate,
            'treeEditorEnabled' => $treeEditorEnabled,
            'hasTreeStructure'  => $hasTreeStructure,
            'treeData'          => $treeData,
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

        // Modul 6 I7b1: Aufgabenbaum-Editor unterhalb des Event-Formulars.
        // Vor-Aggregation Server-seitig (vermeidet zweiten HTTP-Call beim
        // initialen Render); showTaskTree liefert denselben Aggregator-Output
        // spaeter fuer JS-gesteuerte Refreshes.
        $treeEditorEnabled = false;
        $treeData = [];
        $taskCategories = [];
        if ($this->settingsService !== null
            && $this->settingsService->getString('events.tree_editor_enabled', '0') === '1'
            && $this->assignmentRepo !== null
            && $this->treeAggregator !== null
        ) {
            $treeEditorEnabled = true;
            // Aggregator erwartet EventTask-Objekte (siehe TaskTreeAggregator-
            // Docblock) — direkt durchreichen, keine Array-Umwandlung.
            $flatTasks        = $this->taskRepo->findByEvent($id);
            $assignmentCounts = $this->assignmentRepo->countActiveByEvent($id);
            $treeData         = $this->treeAggregator->buildTree($flatTasks, $assignmentCounts);
            $taskCategories   = $this->categoryRepo->findAllActive();
        }

        return $this->render($response, 'admin/events/edit', [
            'title' => 'Event bearbeiten',
            'user' => $user,
            'settings' => $this->settings,
            'event' => $event,
            'users' => $this->userRepo->findAllActive(),
            'organizerIds' => $this->organizerRepo->listUserIdsForEvent($id),
            'treeEditorEnabled' => $treeEditorEnabled,
            'treeData' => $treeData,
            'taskCategories' => $taskCategories,
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

        // Modul 7 I3: Optimistic Locking. Formular transportiert die zum Read-
        // Zeitpunkt gueltige version. Wenn zwischen Laden und Speichern ein anderer
        // Tab/Admin den Datensatz veraendert hat, schlaegt das UPDATE fehl
        // (rowCount = 0).
        $expectedVersion = isset($data['version']) ? (int) $data['version'] : null;
        $updated = $this->eventRepo->update($id, $newState, $expectedVersion);

        // Modul 7 I4: Bei Konflikt die Edit-View mit Diff-Panel rendern, damit
        // der Nutzer Feld fuer Feld sehen kann, was der andere Tab geaendert hat,
        // und seine Eingaben nicht verliert. Die Form wird mit dem aktuellen
        // DB-Stand (frisch gelesen) und der neuen version vorbelegt - "Dein
        // Stand" wird daneben angezeigt, damit der Nutzer bewusst uebernehmen
        // oder verwerfen kann. Kein Force-Apply noetig: der Nutzer schickt das
        // Formular erneut ab und faellt wieder in den regulaeren UPDATE-Pfad.
        if (!$updated) {
            $freshEvent = $this->eventRepo->findById($id);
            if ($freshEvent === null) {
                ViewHelper::flash('danger', 'Event wurde zwischenzeitlich geloescht.');
                return $this->redirect($response, '/admin/events');
            }

            $newOrganizerIdsPosted = array_map('intval', (array) ($data['organizer_ids'] ?? []));
            $currentOrganizerIds = $this->organizerRepo->listUserIdsForEvent($id);

            return $this->render($response, 'admin/events/edit', [
                'title' => 'Event bearbeiten',
                'user' => $user,
                'settings' => $this->settings,
                'event' => $freshEvent,
                'users' => $this->userRepo->findAllActive(),
                'organizerIds' => $currentOrganizerIds,
                'conflictMyState' => $newState + [
                    'organizer_ids' => $newOrganizerIdsPosted,
                ],
                'breadcrumbs' => [
                    ['label' => 'Dashboard', 'url' => '/'],
                    ['label' => 'Events', 'url' => '/admin/events'],
                    ['label' => $freshEvent->getTitle(), 'url' => '/admin/events/' . $id],
                    ['label' => 'Bearbeiten (Konflikt)'],
                ],
            ]);
        }

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

        $this->scheduleEventReminders($event);

        ViewHelper::flash('success', 'Event veroeffentlicht.');
        return $this->redirect($response, '/admin/events/' . $id);
    }

    // =========================================================================
    // POST /admin/events/{id}/complete  -- Abschliessen + Auto-Generate work_entries
    // =========================================================================

    public function complete(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];

        try {
            $result = $this->completionService->completeEvent($id, (int) $user->getId());
            ViewHelper::flash(
                'success',
                sprintf(
                    'Event abgeschlossen. %d Helferstunden-Antraege zur Pruefung erzeugt.',
                    $result['work_entries_created']
                )
            );
        } catch (BusinessRuleException $e) {
            ViewHelper::flash('danger', $e->getMessage());
        } catch (\Throwable $e) {
            ViewHelper::flash(
                'danger',
                'Event-Abschluss fehlgeschlagen (Transaktion zurueckgerollt): ' . $e->getMessage()
            );
        }

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

        $this->cancelEventJobs($id);

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
     * POST /admin/events/{eventId}/tasks/{taskId}/update
     */
    public function updateTask(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $eventId = (int) $this->routeArgs($request)['eventId'];
        $taskId  = (int) $this->routeArgs($request)['taskId'];
        $data = (array) $request->getParsedBody();

        $task = $this->taskRepo->findById($taskId);
        if ($task === null || $task->getEventId() !== $eventId) {
            ViewHelper::flash('danger', 'Aufgabe nicht gefunden.');
            return $this->redirect($response, '/admin/events/' . $eventId);
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            ViewHelper::flash('danger', 'Task-Titel ist Pflicht.');
            return $this->redirect($response, '/admin/events/' . $eventId);
        }

        $taskType     = (string) ($data['task_type'] ?? \App\Models\EventTask::TYPE_AUFGABE);
        $slotMode     = (string) ($data['slot_mode'] ?? \App\Models\EventTask::SLOT_FIX);
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

        $oldValues = [
            'title'           => $task->getTitle(),
            'description'     => $task->getDescription(),
            'task_type'       => $task->getTaskType(),
            'slot_mode'       => $task->getSlotMode(),
            'start_at'        => $task->getStartAt(),
            'end_at'          => $task->getEndAt(),
            'capacity_mode'   => $task->getCapacityMode(),
            'capacity_target' => $task->getCapacityTarget(),
            'hours_default'   => $task->getHoursDefault(),
            'category_id'     => $task->getCategoryId(),
        ];

        $newValues = [
            'title'           => $title,
            'description'     => trim((string) ($data['description'] ?? '')) ?: null,
            'task_type'       => $taskType,
            'slot_mode'       => $slotMode,
            'start_at'        => $slotMode === \App\Models\EventTask::SLOT_FIX ? ($data['task_start_at'] ?? null) : null,
            'end_at'          => $slotMode === \App\Models\EventTask::SLOT_FIX ? ($data['task_end_at']   ?? null) : null,
            'capacity_mode'   => $capacityMode,
            'capacity_target' => !empty($data['capacity_target']) ? (int) $data['capacity_target'] : null,
            'hours_default'   => (float) ($data['hours_default'] ?? 0.0),
            'category_id'     => !empty($data['category_id']) ? (int) $data['category_id'] : null,
            'sort_order'      => 0,
        ];

        $this->taskRepo->update($taskId, $newValues);

        $this->auditService->log(
            action: 'update',
            tableName: 'event_tasks',
            recordId: $taskId,
            oldValues: $oldValues,
            newValues: $newValues,
            description: "Task '$title' in Event #$eventId aktualisiert",
            metadata: ['event_id' => $eventId]
        );

        ViewHelper::flash('success', 'Aufgabe aktualisiert.');
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
    // GET /admin/events/{eventId}/tasks-by-date  (Modul 6 I7b4)
    //
    // Chronologische Leaves-Liste fuer Admins. Read-Only; Task-Titel werden
    // als Link auf /admin/events/{eventId} gerendert, weil der Admin-Kontext
    // die volle Bearbeitungs-UI dort erreicht. Gleiche View wie im
    // Organisator-Kontext; nur linkTaskTitles=true unterscheidet.
    // =========================================================================

    public function tasksByDate(Request $request, Response $response): Response
    {
        $eventId = (int) $this->routeArgs($request)['eventId'];

        if (!$this->treeEditorEnabled()) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        $this->assertEventEditPermission($user, $eventId, $this->organizerRepo);

        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            return $response->withStatus(404);
        }

        if ($this->assignmentRepo === null || $this->treeAggregator === null) {
            return $response->withStatus(404);
        }

        $tasks            = $this->taskRepo->findByEvent($eventId);
        $assignmentCounts = $this->assignmentRepo->countActiveByEvent($eventId);
        $flatList         = $this->treeAggregator->flattenToList($tasks, $assignmentCounts);

        // Primary-Sort: start_at asc (nulls last). PHP-8-usort ist stabil —
        // die Depth-First-Baum-Reihenfolge aus flattenToList bleibt als
        // Sekundaer-Sortier-Schluessel erhalten.
        usort($flatList, static function (array $a, array $b): int {
            $aStart = $a['task']->getStartAt();
            $bStart = $b['task']->getStartAt();
            if ($aStart === null && $bStart === null) {
                return 0;
            }
            if ($aStart === null) {
                return 1;
            }
            if ($bStart === null) {
                return -1;
            }
            return strcmp($aStart, $bStart);
        });

        return $this->render($response, 'admin/events/tasks_by_date', [
            'title' => 'Aufgaben nach Datum — ' . $event->getTitle(),
            'user' => $user,
            'settings' => $this->settings,
            'event' => $event,
            'flatList' => $flatList,
            // Admin-Kontext: Titel als Link auf Admin-Detail-Seite.
            'linkTaskTitles' => true,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Events', 'url' => '/admin/events'],
                ['label' => $event->getTitle(), 'url' => '/admin/events/' . $eventId],
                ['label' => 'Aufgaben nach Datum'],
            ],
        ]);
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

    // =========================================================================
    // Non-modaler Editor (Modul 6 I7e-A Phase 1) — Feature-Parity-Route
    // zum neuen Organizer-Editor. Die acht Tree-Action-POST-Routen unter
    // /admin/events/{id}/tasks/* (showTaskTree, createTaskNode, ...) bleiben
    // unveraendert und werden vom neuen Editor wiederverwendet.
    // =========================================================================

    /**
     * GET /admin/events/{eventId}/editor — Non-modaler Editor-Einstieg.
     * Phase 1: Stub-View. Phase 2 bringt Sidebar + Tree-Widget-Integration.
     */
    public function showEditor(Request $request, Response $response): Response
    {
        $eventId = (int) $this->routeArgs($request)['eventId'];

        if (!$this->treeEditorEnabled()) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        // Gleiches Auth-Pattern wie showTaskTree: event_admin-RoleMiddleware
        // greift bereits an der Route-Group; assertEventEditPermission
        // akzeptiert zusaetzlich Organisator-Membership und wirft
        // AuthorizationException fuer Fremd-User.
        $this->assertEventEditPermission($user, $eventId, $this->organizerRepo);

        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            return $response->withStatus(404);
        }

        // Tree + Sidebar-Daten Server-seitig aggregieren (I7e-A Phase 2).
        $flatTasks        = $this->taskRepo->findByEvent($eventId);
        $assignmentCounts = $this->assignmentRepo->countActiveByEvent($eventId);
        $treeData         = $this->treeAggregator->buildTree($flatTasks, $assignmentCounts);
        $flatList         = $this->treeAggregator->flattenToList($flatTasks, $assignmentCounts);
        $this->sortFlatListByStart($flatList);
        $summary          = $this->computeBelegungsSummary($treeData, $flatList, $assignmentCounts);

        $organizers       = $this->organizerRepo->listForEvent($eventId);
        $taskCategories   = $this->categoryRepo->findAllActive();

        return $this->render($response, 'admin/events/editor', [
            'title'           => 'Editor: ' . $event->getTitle(),
            'user'            => $user,
            'settings'        => $this->settings,
            'event'           => $event,
            'treeData'        => $treeData,
            'flatList'        => $flatList,
            'summary'         => $summary,
            'organizers'      => $organizers,
            'taskCategories'  => $taskCategories,
            'csrfTokenString' => $_SESSION['csrf_token'] ?? '',
            'breadcrumbs'     => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Events', 'url' => '/admin/events'],
                ['label' => $event->getTitle(), 'url' => '/admin/events/' . $eventId],
                ['label' => 'Editor'],
            ],
        ]);
    }

    // =========================================================================
    // Tree-Editor (Modul 6 I7b1) — hinter Settings-Flag events.tree_editor_enabled
    // =========================================================================

    /**
     * GET /admin/events/{eventId}/tasks/tree — liefert den aggregierten Tree als JSON.
     */
    public function showTaskTree(Request $request, Response $response): Response
    {
        $eventId = (int) $this->routeArgs($request)['eventId'];

        if (!$this->treeEditorEnabled()) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        $this->assertEventEditPermission($user, $eventId, $this->organizerRepo);

        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            return $response->withStatus(404);
        }

        $flatTasks        = $this->taskRepo->findByEvent($eventId);
        $assignmentCounts = $this->assignmentRepo->countActiveByEvent($eventId);
        $tree             = $this->treeAggregator->buildTree($flatTasks, $assignmentCounts);

        // Aggregator liefert EventTask-Objekte im 'task'-Feld — fuer JSON in
        // flache Arrays umsetzen, damit die Response serialisierbar wird.
        return $this->json($response, [
            'event_id' => $eventId,
            'tree'     => $this->serializeTreeForJson($tree),
        ]);
    }

    /**
     * POST /admin/events/{eventId}/tasks/node — neuen Knoten anlegen.
     */
    public function createTaskNode(Request $request, Response $response): Response
    {
        $eventId = (int) $this->routeArgs($request)['eventId'];

        if (!$this->treeEditorEnabled()) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        $this->assertEventEditPermission($user, $eventId, $this->organizerRepo);
        $actorId = (int) $user->getId();

        $data = $this->normalizeTreeFormInputs((array) $request->getParsedBody());

        try {
            $newId = $this->treeService->createNode($eventId, $data, $actorId);
        } catch (ValidationException $e) {
            return $this->treeErrorResponse($request, $response, $eventId, 422, $e->getErrors());
        } catch (BusinessRuleException $e) {
            return $this->treeErrorResponse($request, $response, $eventId, 409, [$e->getMessage()]);
        }

        if ($this->wantsJson($request)) {
            return $this->json($response, ['id' => $newId, 'status' => 'ok'], 201);
        }
        ViewHelper::flash('success', 'Aufgabe angelegt.');
        return $this->redirect($response, '/admin/events/' . $eventId);
    }

    /**
     * POST /admin/events/{eventId}/tasks/{taskId}/move — Knoten verschieben.
     */
    public function moveTaskNode(Request $request, Response $response): Response
    {
        $args     = $this->routeArgs($request);
        $eventId  = (int) $args['eventId'];
        $taskId   = (int) $args['taskId'];

        if (!$this->treeEditorEnabled()) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        $this->assertEventEditPermission($user, $eventId, $this->organizerRepo);
        $actorId = (int) $user->getId();

        $data = (array) $request->getParsedBody();
        $newParentId = array_key_exists('new_parent_id', $data) && $data['new_parent_id'] !== null && $data['new_parent_id'] !== ''
            ? (int) $data['new_parent_id']
            : null;
        $newSortOrder = (int) ($data['new_sort_order'] ?? 0);

        try {
            $this->treeService->move($taskId, $newParentId, $newSortOrder, $actorId);
        } catch (ValidationException $e) {
            return $this->treeErrorResponse($request, $response, $eventId, 422, $e->getErrors());
        } catch (BusinessRuleException $e) {
            return $this->treeErrorResponse($request, $response, $eventId, 409, [$e->getMessage()]);
        }

        return $this->treeSuccessResponse($request, $response, $eventId, 'Aufgabe verschoben.');
    }

    /**
     * POST /admin/events/{eventId}/tasks/reorder — Geschwister neu sortieren.
     */
    public function reorderTasks(Request $request, Response $response): Response
    {
        $eventId = (int) $this->routeArgs($request)['eventId'];

        if (!$this->treeEditorEnabled()) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        $this->assertEventEditPermission($user, $eventId, $this->organizerRepo);
        $actorId = (int) $user->getId();

        $data = (array) $request->getParsedBody();
        $parentId = array_key_exists('parent_id', $data) && $data['parent_id'] !== null && $data['parent_id'] !== ''
            ? (int) $data['parent_id']
            : null;
        $orderedIds = array_map('intval', (array) ($data['ordered_task_ids'] ?? []));

        try {
            $this->treeService->reorderSiblings($eventId, $parentId, $orderedIds, $actorId);
        } catch (ValidationException $e) {
            return $this->treeErrorResponse($request, $response, $eventId, 422, $e->getErrors());
        } catch (BusinessRuleException $e) {
            return $this->treeErrorResponse($request, $response, $eventId, 409, [$e->getMessage()]);
        }

        return $this->treeSuccessResponse($request, $response, $eventId, 'Reihenfolge gespeichert.');
    }

    /**
     * POST /admin/events/{eventId}/tasks/{taskId}/convert — Shape-Wechsel
     * (Gruppe <-> Aufgabe). Dispatch per target-Parameter.
     */
    public function convertTaskNode(Request $request, Response $response): Response
    {
        $args    = $this->routeArgs($request);
        $eventId = (int) $args['eventId'];
        $taskId  = (int) $args['taskId'];

        if (!$this->treeEditorEnabled()) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        $this->assertEventEditPermission($user, $eventId, $this->organizerRepo);
        $actorId = (int) $user->getId();

        $data   = $this->normalizeTreeFormInputs((array) $request->getParsedBody());
        $target = $data['target'] ?? null;

        try {
            match ($target) {
                'group' => $this->treeService->convertToGroup($taskId, $actorId),
                'leaf'  => $this->treeService->convertToLeaf($taskId, $data, $actorId),
                default => throw new ValidationException(['target' => 'Unbekannter Convert-Zielwert (erwartet: group|leaf).']),
            };
        } catch (ValidationException $e) {
            return $this->treeErrorResponse($request, $response, $eventId, 422, $e->getErrors());
        } catch (BusinessRuleException $e) {
            return $this->treeErrorResponse($request, $response, $eventId, 409, [$e->getMessage()]);
        }

        return $this->treeSuccessResponse(
            $request,
            $response,
            $eventId,
            $target === 'group' ? 'Knoten in Gruppe konvertiert.' : 'Knoten in Aufgabe konvertiert.'
        );
    }

    /**
     * POST /admin/events/{eventId}/tasks/{taskId}/delete — Soft-Delete.
     */
    public function deleteTaskNode(Request $request, Response $response): Response
    {
        $args    = $this->routeArgs($request);
        $eventId = (int) $args['eventId'];
        $taskId  = (int) $args['taskId'];

        if (!$this->treeEditorEnabled()) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        $this->assertEventEditPermission($user, $eventId, $this->organizerRepo);
        $actorId = (int) $user->getId();

        try {
            $this->treeService->softDeleteNode($taskId, $actorId);
        } catch (ValidationException $e) {
            return $this->treeErrorResponse($request, $response, $eventId, 422, $e->getErrors());
        } catch (BusinessRuleException $e) {
            return $this->treeErrorResponse($request, $response, $eventId, 409, [$e->getMessage()]);
        }

        return $this->treeSuccessResponse($request, $response, $eventId, 'Aufgabe geloescht.');
    }

    /**
     * GET /admin/events/{eventId}/tasks/{taskId}/edit — Task-Daten + Breadcrumb-Pfad.
     * Liefert JSON; das HTML-Modal-Partial kommt in Phase 3.
     */
    public function editTaskNode(Request $request, Response $response): Response
    {
        $args    = $this->routeArgs($request);
        $eventId = (int) $args['eventId'];
        $taskId  = (int) $args['taskId'];

        if (!$this->treeEditorEnabled()) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        $this->assertEventEditPermission($user, $eventId, $this->organizerRepo);

        $task = $this->taskRepo->findById($taskId);
        if ($task === null || $task->getEventId() !== $eventId) {
            return $response->withStatus(404);
        }

        // Aggregator braucht EventTask-Objekte fuer den parent-Walk. Pfad als
        // String bauen, damit das Modal-JS (split ' > ') direkt darauf arbeiten
        // kann — die Struktur-Variante getAncestorPath gibt ein Array zurueck,
        // das Frontend erwartet einen flachen String.
        $flatTasks     = $this->taskRepo->findByEvent($eventId);
        $ancestorNodes = $this->treeAggregator->getAncestorPath($taskId, $flatTasks);
        $titles        = array_map(static fn (array $n) => $n['title'], $ancestorNodes);
        $titles[]      = $task->getTitle();
        $ancestorPath  = implode(' > ', $titles);

        return $this->json($response, [
            'task' => [
                'id'              => $task->getId(),
                'event_id'        => $task->getEventId(),
                'parent_task_id'  => $task->getParentTaskId(),
                'is_group'        => $task->isGroup() ? 1 : 0,
                'category_id'     => $task->getCategoryId(),
                'title'           => $task->getTitle(),
                'description'     => $task->getDescription(),
                'task_type'       => $task->getTaskType(),
                'slot_mode'       => $task->getSlotMode(),
                'start_at'        => $task->getStartAt(),
                'end_at'          => $task->getEndAt(),
                'capacity_mode'   => $task->getCapacityMode(),
                'capacity_target' => $task->getCapacityTarget(),
                'hours_default'   => $task->getHoursDefault(),
                'sort_order'      => $task->getSortOrder(),
            ],
            'ancestor_path' => $ancestorPath,
        ]);
    }

    /**
     * POST /admin/events/{eventId}/tasks/{taskId} — Attribute aktualisieren
     * (ohne Shape-Wechsel; is_group wird vom Service defensiv entfernt).
     */
    public function updateTaskNode(Request $request, Response $response): Response
    {
        $args    = $this->routeArgs($request);
        $eventId = (int) $args['eventId'];
        $taskId  = (int) $args['taskId'];

        if (!$this->treeEditorEnabled()) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        $this->assertEventEditPermission($user, $eventId, $this->organizerRepo);
        $actorId = (int) $user->getId();

        $data = $this->normalizeTreeFormInputs((array) $request->getParsedBody());

        try {
            $this->treeService->updateNode($taskId, $data, $actorId);
        } catch (ValidationException $e) {
            return $this->treeErrorResponse($request, $response, $eventId, 422, $e->getErrors());
        } catch (BusinessRuleException $e) {
            return $this->treeErrorResponse($request, $response, $eventId, 409, [$e->getMessage()]);
        }

        return $this->treeSuccessResponse($request, $response, $eventId, 'Aufgabe aktualisiert.');
    }

    // =========================================================================
    // Tree-Editor — private Helfer
    // =========================================================================

    /**
     * HTTP-Form-Inputs in Service-taugliche Typen ueberfuehren. Zwei konkrete
     * Probleme werden hier geloest:
     *
     *  1) parent_task_id kommt aus HTML-Hidden-Fields immer als String. Der
     *     Service hat eine strikte ?int-Signatur (normalizeParentId) und wirft
     *     unter declare(strict_types=1) einen TypeError bei String-Input.
     *     "" / "0" / null / 0 → null (= Top-Level-Knoten), alles andere (int).
     *
     *  2) Datetime-Inputs (start_at, end_at) und optionale Integer-Felder
     *     (category_id, capacity_target) liefern "" wenn leer gelassen. Der
     *     Service-Null-Check (slot_mode=fix => start/end NOT NULL) unterscheidet
     *     "" und null nicht — ohne Normalisierung landet "" als ungueltiger
     *     DATETIME-String im INSERT und wirft eine PDOException statt einer
     *     lesbaren ValidationException. "" → null, damit die Service-Validation
     *     korrekt greift und der User die Message im Toast sieht.
     */
    private function normalizeTreeFormInputs(array $data): array
    {
        if (array_key_exists('parent_task_id', $data)) {
            $pid = $data['parent_task_id'];
            $data['parent_task_id'] = ($pid === null || $pid === '' || $pid === '0' || $pid === 0)
                ? null
                : (int) $pid;
        }
        foreach (['start_at', 'end_at', 'category_id', 'capacity_target'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }
        return $data;
    }

    /**
     * Flag-Check. Die DB-Settings liegen im gleichen Schluessel, den auch
     * TaskTreeService::assertEnabled() liest — hier nur Lese-Seite.
     */
    private function treeEditorEnabled(): bool
    {
        if ($this->settingsService === null) {
            return false;
        }
        $value = $this->settingsService->getString('events.tree_editor_enabled', '0');
        return $value === '1' || $value === 'true';
    }

    /**
     * Aggregator-Output (task als EventTask-Objekt + aggregierte Subtree-
     * Zahlen) in reine Arrays umsetzen, damit showTaskTree() per json_encode
     * serialisieren kann. Rekursiv, damit 'children' mitgeht.
     *
     * @param array<int, array{task:\App\Models\EventTask, children:array, helpers_subtree:int, hours_subtree:float, leaves_subtree:int, open_slots_subtree:int|null}> $tree
     * @return array<int, array<string, mixed>>
     */
    private function serializeTreeForJson(array $tree): array
    {
        $out = [];
        foreach ($tree as $node) {
            $task = $node['task'];
            $out[] = [
                'id'                 => (int) $task->getId(),
                'event_id'           => $task->getEventId(),
                'parent_task_id'     => $task->getParentTaskId(),
                'is_group'           => $task->isGroup() ? 1 : 0,
                'category_id'        => $task->getCategoryId(),
                'title'              => $task->getTitle(),
                'description'        => $task->getDescription(),
                'task_type'          => $task->getTaskType(),
                'slot_mode'          => $task->getSlotMode(),
                'start_at'           => $task->getStartAt(),
                'end_at'             => $task->getEndAt(),
                'capacity_mode'      => $task->getCapacityMode(),
                'capacity_target'    => $task->getCapacityTarget(),
                'hours_default'      => $task->getHoursDefault(),
                'sort_order'         => $task->getSortOrder(),
                'helpers_subtree'    => $node['helpers_subtree'],
                'hours_subtree'      => $node['hours_subtree'],
                'leaves_subtree'     => $node['leaves_subtree'],
                'open_slots_subtree' => $node['open_slots_subtree'],
                // I7b3: Status als String-Value des TaskStatus-Enums, null
                // wenn Aggregator ohne assignmentCounts aufgerufen wurde.
                'status'             => $node['status']?->value,
                'children'           => $this->serializeTreeForJson($node['children']),
            ];
        }
        return $out;
    }

    private function wantsJson(Request $request): bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    private function treeSuccessResponse(
        Request $request,
        Response $response,
        int $eventId,
        string $flashMessage
    ): Response {
        if ($this->wantsJson($request)) {
            return $this->json($response, ['status' => 'ok']);
        }
        ViewHelper::flash('success', $flashMessage);
        return $this->redirect($response, '/admin/events/' . $eventId);
    }

    private function treeErrorResponse(
        Request $request,
        Response $response,
        int $eventId,
        int $status,
        array $errors
    ): Response {
        if ($this->wantsJson($request)) {
            return $this->json($response, ['status' => 'error', 'errors' => $errors], $status);
        }
        ViewHelper::flash('danger', implode(' ', array_map('strval', $errors)));
        return $this->redirect($response, '/admin/events/' . $eventId);
    }

    // =========================================================================
    // Scheduler-Hooks (Notifications/Reminder)
    // =========================================================================

    /**
     * Plant 7-Tage-, 24h- und Completion-Reminder fuer ein veroeffentlichtes Event.
     * Reminder, deren Zeitpunkt schon vorbei ist, werden uebersprungen.
     */
    private function scheduleEventReminders(Event $event): void
    {
        if ($this->scheduler === null) {
            return;
        }
        $eventId = (int) $event->getId();
        if ($eventId <= 0) {
            return;
        }

        try {
            $startAt = new DateTimeImmutable($event->getStartAt());
            $endAt   = new DateTimeImmutable($event->getEndAt());
        } catch (\Exception) {
            return;
        }

        $now = new DateTimeImmutable();

        $runAt7d = $startAt->sub(new DateInterval('P7D'));
        if ($runAt7d > $now) {
            $this->scheduler->dispatch(
                'event_reminder_7d',
                ['event_id' => $eventId, 'days_before' => 7],
                $runAt7d,
                "event:{$eventId}:reminder:7d"
            );
        }

        $runAt24h = $startAt->sub(new DateInterval('PT24H'));
        if ($runAt24h > $now) {
            $this->scheduler->dispatch(
                'event_reminder_24h',
                ['event_id' => $eventId, 'days_before' => 1],
                $runAt24h,
                "event:{$eventId}:reminder:24h"
            );
        }

        $runAtCompletion = $endAt->add(new DateInterval('PT24H'));
        $this->scheduler->dispatch(
            'event_completion_reminder',
            ['event_id' => $eventId],
            $runAtCompletion,
            "event:{$eventId}:completion_reminder"
        );
    }

    private function cancelEventJobs(int $eventId): void
    {
        if ($this->scheduler === null) {
            return;
        }
        $this->scheduler->cancel("event:{$eventId}:reminder:7d");
        $this->scheduler->cancel("event:{$eventId}:reminder:24h");
        $this->scheduler->cancel("event:{$eventId}:completion_reminder");
    }

    // =========================================================================
    // Sidebar-Helpers (I7e-A Phase 2). Bewusste Duplikate in
    // OrganizerEventEditController — Symmetrie zwischen den beiden
    // showEditor-Implementierungen. Trait-Extraktion bleibt an
    // Follow-up n gekoppelt (gemeinsam mit den Tree-Action-Helpern).
    // =========================================================================

    /**
     * Sortiert die flache Aufgabenliste nach Startzeit. Tasks ohne
     * Startzeit wandern ans Ende.
     *
     * @param list<array{task:EventTask, status:?TaskStatus, helpers:int, open_slots:?int, ancestor_path:list<string>}> $flatList
     */
    private function sortFlatListByStart(array &$flatList): void
    {
        usort($flatList, static function (array $a, array $b): int {
            /** @var EventTask $ta */
            $ta = $a['task'];
            /** @var EventTask $tb */
            $tb = $b['task'];
            $sa = $ta->getStartAt();
            $sb = $tb->getStartAt();
            if ($sa === null || $sa === '') {
                return $sb === null || $sb === '' ? 0 : 1;
            }
            if ($sb === null || $sb === '') {
                return -1;
            }
            return strcmp($sa, $sb);
        });
    }

    /**
     * Aggregiert Belegungs-Zahlen fuer die Editor-Sidebar.
     *
     * Achtung zur Semantik (I7e-A Phase 2c, nachgebessert aus Smoke):
     *   - `helpers_total` = Summe der capacity_target-Werte (Helfer-Soll).
     *   - `zusagen_aktiv` = tatsaechliche aktive Zusagen aus
     *     `$assignmentCounts` (countActiveByEvent).
     *
     * @param array $tree     Root-Nodes aus TaskTreeAggregator::buildTree
     * @param list<array{task:EventTask, status:?TaskStatus, helpers:int, open_slots:?int, ancestor_path:list<string>}> $flatList
     * @param array<int,int> $assignmentCounts  task_id -> Anzahl aktive Zusagen
     * @return array{
     *     leaf_count:int,
     *     group_count:int,
     *     helpers_total:int,
     *     zusagen_aktiv:int,
     *     open_slots:int,
     *     open_slots_known:bool,
     *     hours_default_total:float,
     *     status_counts:array{empty:int, partial:int, full:int}
     * }
     */
    private function computeBelegungsSummary(array $tree, array $flatList, array $assignmentCounts = []): array
    {
        $helpersTotal   = 0;
        $openSlotsTotal = 0;
        $openSlotsKnown = true;
        $hoursTotal     = 0.0;
        $statusCounts   = ['empty' => 0, 'partial' => 0, 'full' => 0];

        foreach ($flatList as $entry) {
            $helpersTotal += (int) $entry['helpers'];
            $hoursTotal   += (float) $entry['task']->getHoursDefault();

            if ($entry['open_slots'] === null) {
                $openSlotsKnown = false;
            } else {
                $openSlotsTotal += (int) $entry['open_slots'];
            }

            if ($entry['status'] instanceof TaskStatus) {
                $statusCounts[$entry['status']->value]++;
            }
        }

        $zusagenAktiv = array_sum(array_map('intval', $assignmentCounts));

        $groupCount = 0;
        $this->walkTreeForSummary($tree, $groupCount);

        return [
            'leaf_count'          => count($flatList),
            'group_count'         => $groupCount,
            'helpers_total'       => $helpersTotal,
            'zusagen_aktiv'       => $zusagenAktiv,
            'open_slots'          => $openSlotsTotal,
            'open_slots_known'    => $openSlotsKnown,
            'hours_default_total' => $hoursTotal,
            'status_counts'       => $statusCounts,
        ];
    }

    /**
     * Rekursiver Walker fuer Gruppen-Count (Sidebar-Panel 2).
     *
     * @param array $nodes
     */
    private function walkTreeForSummary(array $nodes, int &$groupCount): void
    {
        foreach ($nodes as $node) {
            /** @var EventTask $task */
            $task = $node['task'];
            if ($task->isGroup()) {
                $groupCount++;
                $this->walkTreeForSummary((array) ($node['children'] ?? []), $groupCount);
            }
        }
    }
}
