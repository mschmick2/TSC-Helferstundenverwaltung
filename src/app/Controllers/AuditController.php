<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ViewHelper;
use App\Repositories\AuditRepository;
use App\Repositories\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Controller für Audit-Trail-Einsicht (Admin + Auditor)
 */
class AuditController extends BaseController
{
    public function __construct(
        private AuditRepository $auditRepo,
        private UserRepository $userRepo,
        private array $settings,
        private LoggerInterface $logger
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

        // Breadcrumbs: Admin vs. Auditor
        if ($user->isAdmin()) {
            $breadcrumbs = [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Verwaltung', 'url' => '/admin/users'],
                ['label' => 'Audit-Trail'],
            ];
        } else {
            $breadcrumbs = [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Audit-Trail'],
            ];
        }

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
            'breadcrumbs' => $breadcrumbs,
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

        $this->logger->debug('Accessing audit detail', ['audit_id' => $id, 'user_id' => $user->getId()]);

        $entry = $this->auditRepo->findById($id);
        if ($entry === null) {
            $this->logger->warning('Audit entry not found', ['audit_id' => $id]);
            ViewHelper::flash('danger', 'Audit-Eintrag nicht gefunden.');
            return $this->redirect($response, $user->isAdmin() ? '/admin/audit' : '/audit');
        }

        // JSON-Felder dekodieren (mit error handling)
        $oldValues = null;
        $newValues = null;
        $metadata = null;
        
        if (!empty($entry['old_values'])) {
            $decoded = json_decode($entry['old_values'], true);
            if ($decoded !== null || json_last_error() === JSON_ERROR_NONE) {
                $oldValues = $decoded;
            } else {
                $this->logger->warning('Failed to decode old_values JSON', [
                    'audit_id' => $id,
                    'json_error' => json_last_error_msg(),
                    'raw_value' => substr($entry['old_values'], 0, 100)
                ]);
            }
        }
        
        if (!empty($entry['new_values'])) {
            $decoded = json_decode($entry['new_values'], true);
            if ($decoded !== null || json_last_error() === JSON_ERROR_NONE) {
                $newValues = $decoded;
            } else {
                $this->logger->warning('Failed to decode new_values JSON', [
                    'audit_id' => $id,
                    'json_error' => json_last_error_msg(),
                    'raw_value' => substr($entry['new_values'], 0, 100)
                ]);
            }
        }
        
        if (!empty($entry['metadata'])) {
            $decoded = json_decode($entry['metadata'], true);
            if ($decoded !== null || json_last_error() === JSON_ERROR_NONE) {
                $metadata = $decoded;
            } else {
                $this->logger->warning('Failed to decode metadata JSON', [
                    'audit_id' => $id,
                    'json_error' => json_last_error_msg(),
                    'raw_value' => substr($entry['metadata'], 0, 100)
                ]);
            }
        }

        // Breadcrumbs: Admin vs. Auditor
        $auditBasePath = $user->isAdmin() ? '/admin/audit' : '/audit';
        if ($user->isAdmin()) {
            $breadcrumbs = [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Verwaltung', 'url' => '/admin/users'],
                ['label' => 'Audit-Trail', 'url' => $auditBasePath],
                ['label' => 'Audit #' . $id],
            ];
        } else {
            $breadcrumbs = [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Audit-Trail', 'url' => $auditBasePath],
                ['label' => 'Audit #' . $id],
            ];
        }

        return $this->render($response, 'admin/audit/show', [
            'title' => 'Audit-Detail #' . $id,
            'user' => $user,
            'settings' => $this->settings,
            'entry' => $entry,
            'oldValues' => $oldValues,
            'newValues' => $newValues,
            'metadata' => $metadata,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }
}
