<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ViewHelper;
use App\Repositories\CategoryRepository;
use App\Repositories\EventTemplateRepository;
use App\Services\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller fuer Event-Templates (I1: Liste + Create-Stub).
 *
 * Vollstaendige Version mit Save-as-new-version + Task-Editor kommt in I4.
 */
class EventTemplateController extends BaseController
{
    public function __construct(
        private EventTemplateRepository $templateRepo,
        private CategoryRepository $categoryRepo,
        private AuditService $auditService,
        private array $settings
    ) {
    }

    /**
     * GET /admin/event-templates  -- Liste aller aktuellen Versionen
     */
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

    /**
     * POST /admin/event-templates  -- Neues Initial-Template anlegen
     */
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

        ViewHelper::flash('success', 'Template angelegt. Task-Editor kommt in einem spaeteren Increment (I4).');
        return $this->redirect($response, '/admin/event-templates');
    }

    /**
     * GET /admin/event-templates/{id}  -- Detail + Task-Vorlagen
     */
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

        // Alle Versionen ermitteln (root = dieses Template falls ohne parent,
        // sonst muesste man den root traversieren. I1 zeigt nur direkt dieses.)
        $rootId = $template->getParentTemplateId() ?? $id;
        $versions = $this->templateRepo->findAllVersionsByRoot($rootId);

        return $this->render($response, 'admin/event-templates/show', [
            'title' => 'Template: ' . $template->getName(),
            'user' => $user,
            'settings' => $this->settings,
            'template' => $template,
            'tasks' => $tasks,
            'versions' => $versions,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Events', 'url' => '/admin/events'],
                ['label' => 'Templates', 'url' => '/admin/event-templates'],
                ['label' => $template->getName() . ' v' . $template->getVersion()],
            ],
        ]);
    }

    /**
     * POST /admin/event-templates/{id}/delete  -- Soft-Delete
     */
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
}
