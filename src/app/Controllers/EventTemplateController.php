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
        private array $settings
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

        return $this->render($response, 'admin/event-templates/edit', [
            'title' => 'Template bearbeiten: ' . $template->getName(),
            'user' => $user,
            'settings' => $this->settings,
            'template' => $template,
            'tasks' => $tasks,
            'categories' => $categories,
            'hasDerivedEvents' => $hasDerivedEvents,
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
}
