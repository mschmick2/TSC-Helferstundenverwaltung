<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ValidationException;
use App\Helpers\ViewHelper;
use App\Repositories\CategoryRepository;
use App\Repositories\EventTemplateRepository;
use App\Services\AuditService;
use App\Services\EventTemplateService;
use App\Services\SettingsService;
use App\Services\TemplateTaskTreeAggregator;
use App\Services\TemplateTaskTreeService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller fuer Event-Templates (Modul 6 I4):
 *   - Liste, Create, Show, Delete (I1)
 *   - Task-Editor + Save-as-new-Version (I4)
 *   - Event-Ableitung aus Template (I4)
 */
class EventTemplateController extends BaseController
{
    public function __construct(
        private EventTemplateRepository $templateRepo,
        private CategoryRepository $categoryRepo,
        private AuditService $auditService,
        private EventTemplateService $templateService,
        private array $settings,
        private ?TemplateTaskTreeService $treeService = null,
        private ?TemplateTaskTreeAggregator $treeAggregator = null,
        private ?SettingsService $settingsService = null
    ) {
    }

    // =========================================================================
    // Liste + Create (I1)
    // =========================================================================

    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $templates = $this->templateRepo->findCurrent();

        return $this->render($response, 'admin/event-templates/index', [
            'title' => 'Event-Templates',
            'user' => $user,
            'settings' => $this->settings,
            'templates' => $templates,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Verwaltung', 'url' => '/admin/users'],
                ['label' => 'Events', 'url' => '/admin/events'],
                ['label' => 'Templates'],
            ],
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        if ($name === '') {
            ViewHelper::flash('danger', 'Template-Name ist Pflichtfeld.');
            return $this->redirect($response, '/admin/event-templates');
        }

        $id = $this->templateRepo->createInitial(
            $name,
            $description !== '' ? $description : null,
            (int) $user->getId()
        );

        $this->auditService->log(
            action: 'create',
            tableName: 'event_templates',
            recordId: $id,
            newValues: ['name' => $name, 'version' => 1],
            description: "Template '$name' angelegt (v1)"
        );

        ViewHelper::flash('success', 'Template angelegt. Jetzt Tasks hinzufuegen.');
        return $this->redirect($response, '/admin/event-templates/' . $id . '/edit');
    }

    public function show(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];

        $template = $this->templateRepo->findById($id);
        if ($template === null) {
            ViewHelper::flash('danger', 'Template nicht gefunden.');
            return $this->redirect($response, '/admin/event-templates');
        }

        $tasks = $this->templateRepo->findTasksByTemplate($id);
        $rootId = $template->getParentTemplateId() ?? $id;
        $versions = $this->templateRepo->findAllVersionsByRoot($rootId);
        $hasDerivedEvents = $this->templateRepo->hasDerivedEvents($id);

        return $this->render($response, 'admin/event-templates/show', [
            'title' => 'Template: ' . $template->getName(),
            'user' => $user,
            'settings' => $this->settings,
            'template' => $template,
            'tasks' => $tasks,
            'versions' => $versions,
            'hasDerivedEvents' => $hasDerivedEvents,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Events', 'url' => '/admin/events'],
                ['label' => 'Templates', 'url' => '/admin/event-templates'],
                ['label' => $template->getName() . ' v' . $template->getVersion()],
            ],
        ]);
    }

    public function delete(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];

        $template = $this->templateRepo->findById($id);
        if ($template === null) {
            ViewHelper::flash('danger', 'Template nicht gefunden.');
            return $this->redirect($response, '/admin/event-templates');
        }

        $this->templateRepo->softDelete($id, (int) $user->getId());

        $this->auditService->log(
            action: 'delete',
            tableName: 'event_templates',
            recordId: $id,
            description: 'Template soft-deleted: ' . $template->getName()
        );

        ViewHelper::flash('success', 'Template geloescht.');
        return $this->redirect($response, '/admin/event-templates');
    }

    // =========================================================================
    // I4: Task-Editor
    // =========================================================================

    public function edit(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];

        $template = $this->templateRepo->findById($id);
        if ($template === null) {
            ViewHelper::flash('danger', 'Template nicht gefunden.');
            return $this->redirect($response, '/admin/event-templates');
        }
        if (!$template->isCurrent()) {
            ViewHelper::flash('warning',
                'Nur die aktuelle Version ist bearbeitbar. Alte Versionen sind read-only.');
            return $this->redirect($response, '/admin/event-templates/' . $id);
        }

        $tasks = $this->templateRepo->findTasksByTemplate($id);
        $categories = $this->categoryRepo->findAllActive();
        $hasDerivedEvents = $this->templateRepo->hasDerivedEvents($id);

        // I7c Phase 2: Tree-Editor-Daten nur, wenn Flag an UND Template
        // editierbar (isCurrent ist oben bereits garantiert; hier noch der
        // hasDerivedEvents-Lock). Sonst bleibt es bei der flachen
        // Legacy-Liste. Aggregator-Output liefert verschachtelte Struktur
        // fuers Rendering durch das gemeinsame _task_tree_node.php-Partial.
        $treeEditorEnabled = false;
        $treeData = [];
        if ($this->treeEditorEnabled() && !$hasDerivedEvents && $this->treeAggregator !== null) {
            $treeEditorEnabled = true;
            $treeData = $this->treeAggregator->buildTree($tasks);
        }

        return $this->render($response, 'admin/event-templates/edit', [
            'title' => 'Template bearbeiten: ' . $template->getName(),
            'user' => $user,
            'settings' => $this->settings,
            'template' => $template,
            'tasks' => $tasks,
            'categories' => $categories,
            'hasDerivedEvents' => $hasDerivedEvents,
            'treeEditorEnabled' => $treeEditorEnabled,
            'treeData' => $treeData,
            'csrfToken' => $_SESSION['csrf_token'] ?? '',
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Templates', 'url' => '/admin/event-templates'],
                ['label' => $template->getName() . ' v' . $template->getVersion(),
                 'url' => '/admin/event-templates/' . $id],
                ['label' => 'Bearbeiten'],
            ],
        ]);
    }

    public function addTask(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];
        $data = (array) $request->getParsedBody();

        try {
            $this->templateService->addTask($id, $data, (int) $user->getId());
            ViewHelper::flash('success', 'Task hinzugefuegt.');
        } catch (ValidationException | BusinessRuleException $e) {
            ViewHelper::flash('danger', $e->getMessage());
        }

        return $this->redirect($response, '/admin/event-templates/' . $id . '/edit');
    }

    public function updateTask(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $args = $this->routeArgs($request);
        $templateId = (int) $args['id'];
        $taskId = (int) $args['taskId'];
        $data = (array) $request->getParsedBody();

        try {
            $this->templateService->updateTask($taskId, $data, (int) $user->getId());
            ViewHelper::flash('success', 'Task aktualisiert.');
        } catch (ValidationException | BusinessRuleException $e) {
            ViewHelper::flash('danger', $e->getMessage());
        }

        return $this->redirect($response, '/admin/event-templates/' . $templateId . '/edit');
    }

    public function deleteTask(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $args = $this->routeArgs($request);
        $templateId = (int) $args['id'];
        $taskId = (int) $args['taskId'];

        try {
            $this->templateService->deleteTask($taskId, (int) $user->getId());
            ViewHelper::flash('success', 'Task geloescht.');
        } catch (BusinessRuleException $e) {
            ViewHelper::flash('danger', $e->getMessage());
        }

        return $this->redirect($response, '/admin/event-templates/' . $templateId . '/edit');
    }

    // =========================================================================
    // I4: Save-as-new-Version
    // =========================================================================

    public function saveAsNewVersion(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];
        $data = (array) $request->getParsedBody();

        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        try {
            $newId = $this->templateService->saveAsNewVersion(
                $id,
                $name,
                $description !== '' ? $description : null,
                (int) $user->getId()
            );
            ViewHelper::flash('success',
                'Neue Template-Version angelegt. Anpassungen vornehmen und erneut speichern.');
            return $this->redirect($response, '/admin/event-templates/' . $newId . '/edit');
        } catch (ValidationException | BusinessRuleException $e) {
            ViewHelper::flash('danger', $e->getMessage());
            return $this->redirect($response, '/admin/event-templates/' . $id . '/edit');
        }
    }

    // =========================================================================
    // I4: Event-Ableitung
    // =========================================================================

    public function deriveForm(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];

        $template = $this->templateRepo->findById($id);
        if ($template === null) {
            ViewHelper::flash('danger', 'Template nicht gefunden.');
            return $this->redirect($response, '/admin/event-templates');
        }

        $tasks = $this->templateRepo->findTasksByTemplate($id);

        return $this->render($response, 'admin/event-templates/derive', [
            'title' => 'Event aus Template ableiten',
            'user' => $user,
            'settings' => $this->settings,
            'template' => $template,
            'tasks' => $tasks,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Templates', 'url' => '/admin/event-templates'],
                ['label' => $template->getName() . ' v' . $template->getVersion(),
                 'url' => '/admin/event-templates/' . $id],
                ['label' => 'Event ableiten'],
            ],
        ]);
    }

    public function deriveStore(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];
        $data = (array) $request->getParsedBody();

        try {
            $eventId = $this->templateService->deriveEvent($id, $data, (int) $user->getId());
            ViewHelper::flash('success',
                'Event erzeugt aus Template. Status: Entwurf - jetzt Organisatoren zuweisen und veroeffentlichen.');
            return $this->redirect($response, '/admin/events/' . $eventId);
        } catch (ValidationException | BusinessRuleException $e) {
            ViewHelper::flash('danger', $e->getMessage());
            return $this->redirect($response, '/admin/event-templates/' . $id . '/derive');
        }
    }

    // =========================================================================
    // Tree-Editor (Modul 6 I7c) — hinter Settings-Flag events.tree_editor_enabled
    // =========================================================================

    /**
     * GET /admin/event-templates/{templateId}/tasks/tree — aggregierter Tree als JSON.
     */
    public function showTaskTree(Request $request, Response $response): Response
    {
        $templateId = (int) $this->routeArgs($request)['templateId'];

        if (!$this->treeEditorEnabled() || $this->treeAggregator === null) {
            return $response->withStatus(404);
        }

        $template = $this->templateRepo->findById($templateId);
        if ($template === null) {
            return $response->withStatus(404);
        }

        $tasks = $this->templateRepo->findTasksByTemplate($templateId);
        $tree  = $this->treeAggregator->buildTree($tasks);

        return $this->json($response, [
            'template_id' => $templateId,
            'tree'        => $this->serializeTemplateTreeForJson($tree),
        ]);
    }

    /**
     * POST /admin/event-templates/{templateId}/tasks/node
     */
    public function createTaskNode(Request $request, Response $response): Response
    {
        $templateId = (int) $this->routeArgs($request)['templateId'];

        if (!$this->treeEditorEnabled() || $this->treeService === null) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        $actorId = (int) $user->getId();
        $data = $this->normalizeTemplateTreeFormInputs((array) $request->getParsedBody());

        try {
            $newId = $this->treeService->createNode($templateId, $data, $actorId);
        } catch (ValidationException $e) {
            return $this->treeErrorResponse($request, $response, $templateId, 422, $e->getErrors());
        } catch (BusinessRuleException $e) {
            return $this->treeErrorResponse($request, $response, $templateId, 409, [$e->getMessage()]);
        }

        if ($this->wantsJson($request)) {
            return $this->json($response, ['id' => $newId, 'status' => 'ok'], 201);
        }
        ViewHelper::flash('success', 'Aufgabe angelegt.');
        return $this->redirect($response, '/admin/event-templates/' . $templateId . '/edit');
    }

    /**
     * POST /admin/event-templates/{templateId}/tasks/reorder
     */
    public function reorderTasks(Request $request, Response $response): Response
    {
        $templateId = (int) $this->routeArgs($request)['templateId'];

        if (!$this->treeEditorEnabled() || $this->treeService === null) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        $actorId = (int) $user->getId();
        $data = (array) $request->getParsedBody();

        $parentId = $this->normalizeParentIdFromForm($data['parent_template_task_id'] ?? null);
        $orderedIds = array_map('intval', (array) ($data['ordered_ids'] ?? []));

        try {
            $this->treeService->reorderSiblings($templateId, $parentId, $orderedIds, $actorId);
        } catch (ValidationException $e) {
            return $this->treeErrorResponse($request, $response, $templateId, 422, $e->getErrors());
        } catch (BusinessRuleException $e) {
            return $this->treeErrorResponse($request, $response, $templateId, 409, [$e->getMessage()]);
        }

        return $this->treeSuccessResponse(
            $request,
            $response,
            $templateId,
            'Reihenfolge gespeichert.'
        );
    }

    /**
     * POST /admin/event-templates/{templateId}/tasks/{taskId}/move
     */
    public function moveTaskNode(Request $request, Response $response): Response
    {
        $args = $this->routeArgs($request);
        $templateId = (int) $args['templateId'];
        $taskId     = (int) $args['taskId'];

        if (!$this->treeEditorEnabled() || $this->treeService === null) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        $actorId = (int) $user->getId();
        $data = (array) $request->getParsedBody();

        $newParentId = $this->normalizeParentIdFromForm($data['new_parent_template_task_id'] ?? null);
        $newSortOrder = (int) ($data['new_sort_order'] ?? 0);

        try {
            $this->treeService->move($taskId, $newParentId, $newSortOrder, $actorId);
        } catch (ValidationException $e) {
            return $this->treeErrorResponse($request, $response, $templateId, 422, $e->getErrors());
        } catch (BusinessRuleException $e) {
            return $this->treeErrorResponse($request, $response, $templateId, 409, [$e->getMessage()]);
        }

        return $this->treeSuccessResponse($request, $response, $templateId, 'Aufgabe verschoben.');
    }

    /**
     * POST /admin/event-templates/{templateId}/tasks/{taskId}/convert
     * Dispatcht auf convertToGroup oder convertToLeaf anhand target=group|leaf.
     */
    public function convertTaskNode(Request $request, Response $response): Response
    {
        $args = $this->routeArgs($request);
        $templateId = (int) $args['templateId'];
        $taskId     = (int) $args['taskId'];

        if (!$this->treeEditorEnabled() || $this->treeService === null) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        $actorId = (int) $user->getId();
        $data = $this->normalizeTemplateTreeFormInputs((array) $request->getParsedBody());

        $target = (string) ($data['target'] ?? '');

        try {
            if ($target === 'group') {
                $this->treeService->convertToGroup($taskId, $actorId);
            } elseif ($target === 'leaf') {
                $this->treeService->convertToLeaf($taskId, $data, $actorId);
            } else {
                return $this->treeErrorResponse(
                    $request,
                    $response,
                    $templateId,
                    422,
                    ['Ungueltiges Convert-Ziel: target muss "group" oder "leaf" sein.']
                );
            }
        } catch (ValidationException $e) {
            return $this->treeErrorResponse($request, $response, $templateId, 422, $e->getErrors());
        } catch (BusinessRuleException $e) {
            return $this->treeErrorResponse($request, $response, $templateId, 409, [$e->getMessage()]);
        }

        return $this->treeSuccessResponse($request, $response, $templateId, 'Knoten konvertiert.');
    }

    /**
     * POST /admin/event-templates/{templateId}/tasks/{taskId}/tree-delete
     */
    public function deleteTaskNode(Request $request, Response $response): Response
    {
        $args = $this->routeArgs($request);
        $templateId = (int) $args['templateId'];
        $taskId     = (int) $args['taskId'];

        if (!$this->treeEditorEnabled() || $this->treeService === null) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        $actorId = (int) $user->getId();

        try {
            $this->treeService->deleteNode($taskId, $actorId);
        } catch (BusinessRuleException $e) {
            return $this->treeErrorResponse($request, $response, $templateId, 409, [$e->getMessage()]);
        }

        return $this->treeSuccessResponse($request, $response, $templateId, 'Aufgabe geloescht.');
    }

    /**
     * GET /admin/event-templates/{templateId}/tasks/{taskId}/edit — Modal-Data.
     * Liefert Task-Felder + Breadcrumb-Pfad im Template-Baum.
     */
    public function editTaskNode(Request $request, Response $response): Response
    {
        $args = $this->routeArgs($request);
        $templateId = (int) $args['templateId'];
        $taskId     = (int) $args['taskId'];

        if (!$this->treeEditorEnabled() || $this->treeAggregator === null) {
            return $response->withStatus(404);
        }

        $task = $this->templateRepo->findTaskById($taskId);
        if ($task === null || $task->getTemplateId() !== $templateId) {
            return $response->withStatus(404);
        }

        $allTasks = $this->templateRepo->findTasksByTemplate($templateId);
        $path     = $this->treeAggregator->getPathString($taskId, $allTasks);

        return $this->json($response, [
            'id'                           => $task->getId(),
            'template_id'                  => $task->getTemplateId(),
            'parent_template_task_id'      => $task->getParentTemplateTaskId(),
            'is_group'                     => $task->isGroup() ? 1 : 0,
            'category_id'                  => $task->getCategoryId(),
            'title'                        => $task->getTitle(),
            'description'                  => $task->getDescription(),
            'task_type'                    => $task->getTaskType(),
            'slot_mode'                    => $task->getSlotMode(),
            'default_offset_minutes_start' => $task->getDefaultOffsetMinutesStart(),
            'default_offset_minutes_end'   => $task->getDefaultOffsetMinutesEnd(),
            'capacity_mode'                => $task->getCapacityMode(),
            'capacity_target'              => $task->getCapacityTarget(),
            'hours_default'                => $task->getHoursDefault(),
            'sort_order'                   => $task->getSortOrder(),
            'ancestor_path'                => $path,
        ]);
    }

    /**
     * POST /admin/event-templates/{templateId}/tasks/{taskId} — updateNode.
     */
    public function updateTaskNode(Request $request, Response $response): Response
    {
        $args = $this->routeArgs($request);
        $templateId = (int) $args['templateId'];
        $taskId     = (int) $args['taskId'];

        if (!$this->treeEditorEnabled() || $this->treeService === null) {
            return $response->withStatus(404);
        }

        $user = $request->getAttribute('user');
        $actorId = (int) $user->getId();
        $data = $this->normalizeTemplateTreeFormInputs((array) $request->getParsedBody());

        try {
            $this->treeService->updateNode($taskId, $data, $actorId);
        } catch (ValidationException $e) {
            return $this->treeErrorResponse($request, $response, $templateId, 422, $e->getErrors());
        } catch (BusinessRuleException $e) {
            return $this->treeErrorResponse($request, $response, $templateId, 409, [$e->getMessage()]);
        }

        return $this->treeSuccessResponse($request, $response, $templateId, 'Aufgabe aktualisiert.');
    }

    // =========================================================================
    // Private Helfer (Tree-Editor)
    // =========================================================================

    /**
     * HTTP-String → Service-strict-Type-Normalisierung fuer Template-Tasks.
     * Analog zu EventAdminController::normalizeTreeFormInputs, angepasst auf
     * die Template-Feldnamen (parent_template_task_id, Offset-Minuten).
     */
    private function normalizeTemplateTreeFormInputs(array $data): array
    {
        if (array_key_exists('parent_template_task_id', $data)) {
            $pid = $data['parent_template_task_id'];
            $data['parent_template_task_id'] =
                ($pid === null || $pid === '' || $pid === '0' || $pid === 0)
                    ? null
                    : (int) $pid;
        }
        foreach (
            [
                'default_offset_minutes_start',
                'default_offset_minutes_end',
                'category_id',
                'capacity_target',
            ] as $field
        ) {
            if (array_key_exists($field, $data) && $data[$field] === '') {
                $data[$field] = null;
            }
        }
        return $data;
    }

    private function normalizeParentIdFromForm(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '0' || $value === 0) {
            return null;
        }
        return (int) $value;
    }

    private function treeEditorEnabled(): bool
    {
        if ($this->settingsService === null) {
            return false;
        }
        $value = $this->settingsService->getString('events.tree_editor_enabled', '0');
        return $value === '1' || $value === 'true';
    }

    /**
     * @param array<int, array{task:\App\Models\EventTemplateTask, children:array, helpers_subtree:int, hours_subtree:float, leaves_subtree:int}> $tree
     * @return array<int, array<string, mixed>>
     */
    private function serializeTemplateTreeForJson(array $tree): array
    {
        $out = [];
        foreach ($tree as $node) {
            $task = $node['task'];
            $out[] = [
                'id'                           => (int) $task->getId(),
                'template_id'                  => $task->getTemplateId(),
                'parent_template_task_id'      => $task->getParentTemplateTaskId(),
                'is_group'                     => $task->isGroup() ? 1 : 0,
                'category_id'                  => $task->getCategoryId(),
                'title'                        => $task->getTitle(),
                'description'                  => $task->getDescription(),
                'task_type'                    => $task->getTaskType(),
                'slot_mode'                    => $task->getSlotMode(),
                'default_offset_minutes_start' => $task->getDefaultOffsetMinutesStart(),
                'default_offset_minutes_end'   => $task->getDefaultOffsetMinutesEnd(),
                'capacity_mode'                => $task->getCapacityMode(),
                'capacity_target'              => $task->getCapacityTarget(),
                'hours_default'                => $task->getHoursDefault(),
                'sort_order'                   => $task->getSortOrder(),
                'helpers_subtree'              => $node['helpers_subtree'],
                'hours_subtree'                => $node['hours_subtree'],
                'leaves_subtree'               => $node['leaves_subtree'],
                'children'                     => $this->serializeTemplateTreeForJson($node['children']),
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
        int $templateId,
        string $flashMessage
    ): Response {
        if ($this->wantsJson($request)) {
            return $this->json($response, ['status' => 'ok']);
        }
        ViewHelper::flash('success', $flashMessage);
        return $this->redirect($response, '/admin/event-templates/' . $templateId . '/edit');
    }

    private function treeErrorResponse(
        Request $request,
        Response $response,
        int $templateId,
        int $status,
        array $errors
    ): Response {
        if ($this->wantsJson($request)) {
            return $this->json($response, ['status' => 'error', 'errors' => $errors], $status);
        }
        ViewHelper::flash('danger', implode(' ', array_map('strval', $errors)));
        return $this->redirect($response, '/admin/event-templates/' . $templateId . '/edit');
    }
}
