<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ViewHelper;
use App\Repositories\AuditRepository;
use App\Repositories\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller für Audit-Trail-Einsicht (Admin + Auditor)
 */
class AuditController extends BaseController
{
    public function __construct(
        private AuditRepository $auditRepo,
        private UserRepository $userRepo,
        private array $settings
    ) {
    }

    /**
     * Audit-Trail-Liste (GET /admin/audit oder /audit)
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = 50;

        $result = $this->auditRepo->findPaginated(
            $page,
            $perPage,
            action: $params['action'] ?? null,
            userId: isset($params['user_id']) && $params['user_id'] !== '' ? (int) $params['user_id'] : null,
            tableName: $params['table_name'] ?? null,
            dateFrom: $params['date_from'] ?? null,
            dateTo: $params['date_to'] ?? null,
            entryNumber: $params['entry_number'] ?? null
        );

        $actions = $this->auditRepo->getDistinctActions();
        $tableNames = $this->auditRepo->getDistinctTableNames();

        return $this->render($response, 'admin/audit/index', [
            'title' => 'Audit-Trail',
            'user' => $user,
            'settings' => $this->settings,
            'entries' => $result['entries'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
            'actions' => $actions,
            'tableNames' => $tableNames,
            'filters' => [
                'action' => $params['action'] ?? '',
                'user_id' => $params['user_id'] ?? '',
                'table_name' => $params['table_name'] ?? '',
                'date_from' => $params['date_from'] ?? '',
                'date_to' => $params['date_to'] ?? '',
                'entry_number' => $params['entry_number'] ?? '',
            ],
        ]);
    }

    /**
     * Audit-Eintrag Detail (GET /admin/audit/{id} oder /audit/{id})
     */
    public function show(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $args = $this->routeArgs($request);
        $id = (int) $args['id'];

        $entry = $this->auditRepo->findById($id);
        if ($entry === null) {
            ViewHelper::flash('danger', 'Audit-Eintrag nicht gefunden.');
            return $this->redirect($response, $user->isAdmin() ? '/admin/audit' : '/audit');
        }

        // JSON-Felder dekodieren
        $oldValues = $entry['old_values'] ? json_decode($entry['old_values'], true) : null;
        $newValues = $entry['new_values'] ? json_decode($entry['new_values'], true) : null;
        $metadata = $entry['metadata'] ? json_decode($entry['metadata'], true) : null;

        return $this->render($response, 'admin/audit/show', [
            'title' => 'Audit-Detail #' . $id,
            'user' => $user,
            'settings' => $this->settings,
            'entry' => $entry,
            'oldValues' => $oldValues,
            'newValues' => $newValues,
            'metadata' => $metadata,
        ]);
    }
}
