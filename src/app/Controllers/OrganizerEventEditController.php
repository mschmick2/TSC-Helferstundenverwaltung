<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Helpers\ViewHelper;
use App\Models\Event;
use App\Repositories\EventOrganizerRepository;
use App\Repositories\EventRepository;
use App\Repositories\EventTaskAssignmentRepository;
use App\Repositories\EventTaskRepository;
use App\Services\SettingsService;
use App\Services\TaskTreeAggregator;
use App\Services\TaskTreeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Non-modaler Organisator-Editor fuer den Event-Aufgabenbaum
 * (Modul 6 I7e-A).
 *
 * Parallel-Controller zum Admin-Tree-Editor (EventAdminController-Tree-
 * Actions seit I7b1). Funktional identisches Verhalten, unterscheidet
 * sich in zwei Punkten:
 *
 *   1. Authorization: isOrganizer-Check im Controller statt
 *      RoleMiddleware auf der Route-Group. Die /organizer-Group hat
 *      per Design keine Rollen-Pruefung; Owner-Check passiert
 *      defensive hier, VOR jedem findById-/Service-Aufruf
 *      (Information-Leak-Schutz; Muster aus I7b4 tasksByDate).
 *
 *   2. Redirect-Ziel bei Success: /organizer/events/{id}/editor
 *      statt der Admin-Detail-Seite. Damit bleibt der User im
 *      Editor-Kontext und sieht seine Aenderung nach dem Reload
 *      direkt wieder.
 *
 * Die Helfer-Methoden (normalizeTreeFormInputs, treeEditorEnabled,
 * serializeTreeForJson, wantsJson, treeSuccessResponse,
 * treeErrorResponse) sind absichtlich als Duplikate aus
 * EventAdminController uebernommen — bewusste temporaere Doppelung,
 * bis drei Controller laufen. Die Trait-Extraktion bleibt Follow-up n
 * aus dem Modul-6-Plan und wird gezielt nach stabilen drei
 * Implementierungen durchgefuehrt (Risiko-Minimierung:
 * Feature-Inkrement nicht mit Refactor-Inkrement mischen).
 *
 * Phase 1: Controller + Routen + DI + Stub-View. Die eigentliche
 * Editor-UI mit Sidebar + Tree-Widget folgt in Phase 2.
 */
class OrganizerEventEditController extends BaseController
{
    public function __construct(
        private EventRepository $eventRepo,
        private EventTaskRepository $taskRepo,
        private EventTaskAssignmentRepository $assignmentRepo,
        private EventOrganizerRepository $organizerRepo,
        private TaskTreeService $treeService,
        private TaskTreeAggregator $treeAggregator,
        private SettingsService $settingsService,
        private array $settings
    ) {
    }

    // =========================================================================
    // Editor-Seite — GET /organizer/events/{eventId}/editor
    // =========================================================================

    public function showEditor(Request $request, Response $response): Response
    {
        $eventId = (int) $this->routeArgs($request)['eventId'];

        if (!$this->treeEditorEnabled()) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        if (!$this->organizerRepo->isOrganizer($eventId, (int) $user->getId())) {
            return $response->withStatus(403);
        }

        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            return $response->withStatus(404);
        }

        return $this->render($response, 'organizer/events/editor', [
            'title'    => 'Editor: ' . $event->getTitle(),
            'user'     => $user,
            'settings' => $this->settings,
            'event'    => $event,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Als Organisator', 'url' => '/organizer/events'],
                ['label' => $event->getTitle() . ' — Editor'],
            ],
        ]);
    }

    // =========================================================================
    // Tree-Actions — analog zu EventAdminController, mit isOrganizer-Gate
    // =========================================================================

    /**
     * GET /organizer/events/{eventId}/tasks/tree — aggregierter Tree als JSON.
     */
    public function showTaskTree(Request $request, Response $response): Response
    {
        $eventId = (int) $this->routeArgs($request)['eventId'];

        if (!$this->treeEditorEnabled()) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        if (!$this->organizerRepo->isOrganizer($eventId, (int) $user->getId())) {
            return $response->withStatus(403);
        }

        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            return $response->withStatus(404);
        }

        $flatTasks        = $this->taskRepo->findByEvent($eventId);
        $assignmentCounts = $this->assignmentRepo->countActiveByEvent($eventId);
        $tree             = $this->treeAggregator->buildTree($flatTasks, $assignmentCounts);

        return $this->json($response, [
            'event_id' => $eventId,
            'tree'     => $this->serializeTreeForJson($tree),
        ]);
    }

    /**
     * POST /organizer/events/{eventId}/tasks/node — neuen Knoten anlegen.
     */
    public function createTaskNode(Request $request, Response $response): Response
    {
        $eventId = (int) $this->routeArgs($request)['eventId'];

        if (!$this->treeEditorEnabled()) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        if (!$this->organizerRepo->isOrganizer($eventId, (int) $user->getId())) {
            return $response->withStatus(403);
        }
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
        return $this->redirect($response, '/organizer/events/' . $eventId . '/editor');
    }

    /**
     * POST /organizer/events/{eventId}/tasks/{taskId}/move — Knoten verschieben.
     */
    public function moveTaskNode(Request $request, Response $response): Response
    {
        $args    = $this->routeArgs($request);
        $eventId = (int) $args['eventId'];
        $taskId  = (int) $args['taskId'];

        if (!$this->treeEditorEnabled()) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        if (!$this->organizerRepo->isOrganizer($eventId, (int) $user->getId())) {
            return $response->withStatus(403);
        }
        $actorId = (int) $user->getId();

        $data = (array) $request->getParsedBody();
        $newParentId = array_key_exists('new_parent_id', $data)
            && $data['new_parent_id'] !== null
            && $data['new_parent_id'] !== ''
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
     * POST /organizer/events/{eventId}/tasks/reorder — Geschwister neu sortieren.
     */
    public function reorderTasks(Request $request, Response $response): Response
    {
        $eventId = (int) $this->routeArgs($request)['eventId'];

        if (!$this->treeEditorEnabled()) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        if (!$this->organizerRepo->isOrganizer($eventId, (int) $user->getId())) {
            return $response->withStatus(403);
        }
        $actorId = (int) $user->getId();

        $data = (array) $request->getParsedBody();
        $parentId = array_key_exists('parent_id', $data)
            && $data['parent_id'] !== null
            && $data['parent_id'] !== ''
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
     * POST /organizer/events/{eventId}/tasks/{taskId}/convert — Shape-Wechsel
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
        if (!$this->organizerRepo->isOrganizer($eventId, (int) $user->getId())) {
            return $response->withStatus(403);
        }
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
     * POST /organizer/events/{eventId}/tasks/{taskId}/tree-delete — Soft-Delete.
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
        if (!$this->organizerRepo->isOrganizer($eventId, (int) $user->getId())) {
            return $response->withStatus(403);
        }
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
     * GET /organizer/events/{eventId}/tasks/{taskId}/edit — Task-Daten + Pfad.
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
        if (!$this->organizerRepo->isOrganizer($eventId, (int) $user->getId())) {
            return $response->withStatus(403);
        }

        $task = $this->taskRepo->findById($taskId);
        if ($task === null || $task->getEventId() !== $eventId) {
            return $response->withStatus(404);
        }

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
     * POST /organizer/events/{eventId}/tasks/{taskId} — Attribute aktualisieren.
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
        if (!$this->organizerRepo->isOrganizer($eventId, (int) $user->getId())) {
            return $response->withStatus(403);
        }
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
    // Duplikat-Helfer (aus EventAdminController; Follow-up n: Trait-Extraktion)
    //
    // Bewusste temporaere Doppelung, bis drei Controller laufen. Die einzigen
    // fachlichen Abweichungen sind die Redirect-Ziele in treeSuccessResponse/
    // treeErrorResponse ('/organizer/events/{id}/editor' statt
    // '/admin/events/{id}').
    // =========================================================================

    /**
     * Duplikat aus EventAdminController, Trait-Extraktion in Follow-up n.
     * HTTP-Form-Inputs in Service-taugliche Typen ueberfuehren — siehe
     * EventAdminController::normalizeTreeFormInputs fuer die ausfuehrliche
     * Begruendung der "" → null-Normalisierung.
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
     * Duplikat aus EventAdminController, Trait-Extraktion in Follow-up n.
     */
    private function treeEditorEnabled(): bool
    {
        $value = $this->settingsService->getString('events.tree_editor_enabled', '0');
        return $value === '1' || $value === 'true';
    }

    /**
     * Duplikat aus EventAdminController, Trait-Extraktion in Follow-up n.
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
                'status'             => $node['status']?->value,
                'children'           => $this->serializeTreeForJson($node['children']),
            ];
        }
        return $out;
    }

    /**
     * Duplikat aus EventAdminController, Trait-Extraktion in Follow-up n.
     */
    private function wantsJson(Request $request): bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    /**
     * Duplikat aus EventAdminController mit Redirect-Abweichung: das Success-
     * Target zeigt auf /organizer/events/{id}/editor statt /admin/events/{id},
     * damit der User nach einer Tree-Operation im Editor-Kontext bleibt.
     * Trait-Extraktion in Follow-up n.
     */
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
        return $this->redirect($response, '/organizer/events/' . $eventId . '/editor');
    }

    /**
     * Duplikat aus EventAdminController mit Redirect-Abweichung (siehe
     * treeSuccessResponse). Trait-Extraktion in Follow-up n.
     */
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
        return $this->redirect($response, '/organizer/events/' . $eventId . '/editor');
    }
}
