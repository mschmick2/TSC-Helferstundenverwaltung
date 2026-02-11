<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ViewHelper;
use App\Repositories\CategoryRepository;
use App\Services\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller für Kategorien-Verwaltung (Admin)
 */
class CategoryController extends BaseController
{
    public function __construct(
        private CategoryRepository $categoryRepo,
        private AuditService $auditService,
        private array $settings
    ) {
    }

    /**
     * Kategorien-Liste (GET /admin/categories)
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $categories = $this->categoryRepo->findAllForAdmin();

        // Eintrags-Anzahl pro Kategorie laden
        $entryCounts = [];
        foreach ($categories as $cat) {
            $entryCounts[$cat->getId()] = $this->categoryRepo->countEntriesForCategory($cat->getId());
        }

        return $this->render($response, 'admin/categories/index', [
            'title' => 'Kategorien verwalten',
            'user' => $user,
            'settings' => $this->settings,
            'categories' => $categories,
            'entryCounts' => $entryCounts,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Verwaltung', 'url' => '/admin/users'],
                ['label' => 'Kategorien'],
            ],
        ]);
    }

    /**
     * Neue Kategorie erstellen (POST /admin/categories)
     */
    public function store(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();

        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $sortOrder = (int) ($data['sort_order'] ?? 0);

        // Validierung
        if ($name === '') {
            ViewHelper::flash('danger', 'Der Name ist ein Pflichtfeld.');
            return $this->redirect($response, '/admin/categories');
        }

        if (mb_strlen($name) > 100) {
            ViewHelper::flash('danger', 'Der Name darf maximal 100 Zeichen lang sein.');
            return $this->redirect($response, '/admin/categories');
        }

        $categoryId = $this->categoryRepo->create([
            'name' => $name,
            'description' => $description ?: null,
            'sort_order' => $sortOrder,
            'is_active' => 1,
        ]);

        $this->auditService->log(
            'create',
            'categories',
            $categoryId,
            newValues: ['name' => $name, 'description' => $description, 'sort_order' => $sortOrder],
            description: "Kategorie erstellt: {$name}"
        );

        ViewHelper::flash('success', 'Kategorie wurde erfolgreich erstellt.');
        return $this->redirect($response, '/admin/categories');
    }

    /**
     * Kategorie aktualisieren (POST /admin/categories/{id})
     */
    public function update(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $args = $this->routeArgs($request);
        $id = (int) $args['id'];
        $data = $request->getParsedBody();

        $category = $this->categoryRepo->findByIdForAdmin($id);
        if ($category === null) {
            ViewHelper::flash('danger', 'Kategorie nicht gefunden.');
            return $this->redirect($response, '/admin/categories');
        }

        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $sortOrder = (int) ($data['sort_order'] ?? 0);

        if ($name === '') {
            ViewHelper::flash('danger', 'Der Name ist ein Pflichtfeld.');
            return $this->redirect($response, '/admin/categories');
        }

        if (mb_strlen($name) > 100) {
            ViewHelper::flash('danger', 'Der Name darf maximal 100 Zeichen lang sein.');
            return $this->redirect($response, '/admin/categories');
        }

        $oldValues = $this->categoryRepo->getRawById($id);

        $this->categoryRepo->update($id, [
            'name' => $name,
            'description' => $description ?: null,
            'sort_order' => $sortOrder,
            'is_active' => $category->isActive() ? 1 : 0,
        ]);

        $this->auditService->log(
            'update',
            'categories',
            $id,
            oldValues: $oldValues,
            newValues: ['name' => $name, 'description' => $description, 'sort_order' => $sortOrder],
            description: "Kategorie aktualisiert: {$name}"
        );

        ViewHelper::flash('success', 'Kategorie wurde aktualisiert.');
        return $this->redirect($response, '/admin/categories');
    }

    /**
     * Kategorie deaktivieren (POST /admin/categories/{id}/deactivate)
     */
    public function deactivate(Request $request, Response $response): Response
    {
        $args = $this->routeArgs($request);
        $id = (int) $args['id'];
        $category = $this->categoryRepo->findByIdForAdmin($id);

        if ($category === null) {
            ViewHelper::flash('danger', 'Kategorie nicht gefunden.');
            return $this->redirect($response, '/admin/categories');
        }

        $this->categoryRepo->deactivate($id);
        $this->auditService->log(
            'update',
            'categories',
            $id,
            oldValues: ['is_active' => true],
            newValues: ['is_active' => false],
            description: "Kategorie deaktiviert: {$category->getName()}"
        );

        ViewHelper::flash('success', 'Kategorie wurde deaktiviert.');
        return $this->redirect($response, '/admin/categories');
    }

    /**
     * Kategorie aktivieren (POST /admin/categories/{id}/activate)
     */
    public function activate(Request $request, Response $response): Response
    {
        $args = $this->routeArgs($request);
        $id = (int) $args['id'];
        $category = $this->categoryRepo->findByIdForAdmin($id);

        if ($category === null) {
            ViewHelper::flash('danger', 'Kategorie nicht gefunden.');
            return $this->redirect($response, '/admin/categories');
        }

        $this->categoryRepo->activate($id);
        $this->auditService->log(
            'update',
            'categories',
            $id,
            oldValues: ['is_active' => false],
            newValues: ['is_active' => true],
            description: "Kategorie aktiviert: {$category->getName()}"
        );

        ViewHelper::flash('success', 'Kategorie wurde aktiviert.');
        return $this->redirect($response, '/admin/categories');
    }

    /**
     * Kategorie soft-löschen (POST /admin/categories/{id}/delete)
     */
    public function delete(Request $request, Response $response): Response
    {
        $args = $this->routeArgs($request);
        $id = (int) $args['id'];
        $category = $this->categoryRepo->findByIdForAdmin($id);

        if ($category === null) {
            ViewHelper::flash('danger', 'Kategorie nicht gefunden.');
            return $this->redirect($response, '/admin/categories');
        }

        $entryCount = $this->categoryRepo->countEntriesForCategory($id);
        if ($entryCount > 0) {
            ViewHelper::flash('warning', "Diese Kategorie wird von {$entryCount} Einträgen verwendet und kann nur deaktiviert, nicht gelöscht werden.");
            return $this->redirect($response, '/admin/categories');
        }

        $oldValues = $this->categoryRepo->getRawById($id);
        $this->categoryRepo->softDelete($id);

        $this->auditService->log(
            'delete',
            'categories',
            $id,
            oldValues: $oldValues,
            description: "Kategorie gelöscht: {$category->getName()}"
        );

        ViewHelper::flash('success', 'Kategorie wurde gelöscht.');
        return $this->redirect($response, '/admin/categories');
    }

    /**
     * Sortierung aktualisieren (POST /admin/categories/reorder) - AJAX
     */
    public function reorder(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $orders = $data['orders'] ?? [];

        if (!is_array($orders)) {
            return $this->json($response, ['error' => 'Ungültige Daten'], 400);
        }

        foreach ($orders as $item) {
            if (isset($item['id'], $item['sort_order'])) {
                $this->categoryRepo->updateSortOrder(
                    (int) $item['id'],
                    (int) $item['sort_order']
                );
            }
        }

        return $this->json($response, ['success' => true]);
    }
}
