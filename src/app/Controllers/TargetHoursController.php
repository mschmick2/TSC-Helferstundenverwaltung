<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ViewHelper;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\TargetHoursService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller für Soll-Stunden-Verwaltung (Admin)
 */
class TargetHoursController extends BaseController
{
    public function __construct(
        private TargetHoursService $targetService,
        private UserRepository $userRepo,
        private AuditService $auditService,
        private array $settings
    ) {
    }

    /**
     * Übersicht (GET /admin/targets)
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => '/'],
            ['label' => 'Verwaltung', 'url' => '/admin/users'],
            ['label' => 'Soll-Stunden'],
        ];

        if (!$this->targetService->isEnabled()) {
            return $this->render($response, 'admin/targets/index', [
                'title' => 'Soll-Stunden',
                'user' => $user,
                'settings' => $this->settings,
                'enabled' => false,
                'comparisons' => [],
                'year' => (int) date('Y'),
                'defaultTarget' => $this->targetService->getDefaultTarget(),
                'onlyUnfulfilled' => false,
                'breadcrumbs' => $breadcrumbs,
            ]);
        }

        $year = (int) ($params['year'] ?? date('Y'));
        $onlyUnfulfilled = isset($params['unfulfilled']) && $params['unfulfilled'] === '1';

        $comparisons = $this->targetService->getAllComparisons($year);

        // Nur nicht erfüllte filtern
        if ($onlyUnfulfilled) {
            $comparisons = array_filter($comparisons, function ($row) {
                return !$row['is_exempt'] && (float) $row['actual_hours'] < (float) $row['target_hours'];
            });
        }

        return $this->render($response, 'admin/targets/index', [
            'title' => 'Soll-Stunden ' . $year,
            'user' => $user,
            'settings' => $this->settings,
            'enabled' => true,
            'comparisons' => $comparisons,
            'year' => $year,
            'defaultTarget' => $this->targetService->getDefaultTarget(),
            'onlyUnfulfilled' => $onlyUnfulfilled,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    /**
     * Einzelziel bearbeiten (GET /admin/targets/{userId})
     */
    public function editUser(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('user');
        $args = $this->routeArgs($request);
        $userId = (int) $args['userId'];
        $params = $request->getQueryParams();
        $year = (int) ($params['year'] ?? date('Y'));

        $targetUser = $this->userRepo->findByIdForAdmin($userId);
        if ($targetUser === null) {
            ViewHelper::flash('danger', 'Benutzer nicht gefunden.');
            return $this->redirect($response, '/admin/targets');
        }

        $comparison = $this->targetService->getUserComparison($userId, $year);

        return $this->render($response, 'admin/targets/edit', [
            'title' => 'Soll-Stunden: ' . $targetUser->getVollname(),
            'user' => $currentUser,
            'settings' => $this->settings,
            'targetUser' => $targetUser,
            'comparison' => $comparison,
            'year' => $year,
            'defaultTarget' => $this->targetService->getDefaultTarget(),
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Verwaltung', 'url' => '/admin/users'],
                ['label' => 'Soll-Stunden', 'url' => '/admin/targets'],
                ['label' => $targetUser->getVollname()],
            ],
        ]);
    }

    /**
     * Einzelziel speichern (POST /admin/targets/{userId})
     */
    public function updateUser(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('user');
        $args = $this->routeArgs($request);
        $userId = (int) $args['userId'];
        $data = $request->getParsedBody();

        $year = (int) ($data['year'] ?? date('Y'));
        $targetHours = (float) str_replace(',', '.', $data['target_hours'] ?? '0');
        $isExempt = isset($data['is_exempt']) && $data['is_exempt'] === '1';
        $notes = trim($data['notes'] ?? '');

        $this->targetService->setIndividualTarget(
            $userId,
            $year,
            $targetHours,
            $isExempt,
            $notes ?: null,
            $currentUser->getId()
        );

        ViewHelper::flash('success', 'Soll-Stunden wurden aktualisiert.');
        return $this->redirect($response, '/admin/targets?year=' . $year);
    }

    /**
     * Massenaktualisierung (POST /admin/targets/bulk)
     */
    public function bulkUpdate(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('user');
        $data = $request->getParsedBody();
        $year = (int) ($data['year'] ?? date('Y'));
        $targets = $data['targets'] ?? [];
        $count = 0;

        foreach ($targets as $userId => $target) {
            $hours = (float) str_replace(',', '.', $target['hours'] ?? '0');
            $isExempt = isset($target['exempt']) && $target['exempt'] === '1';
            $notes = trim($target['notes'] ?? '');

            $this->targetService->setIndividualTarget(
                (int) $userId,
                $year,
                $hours,
                $isExempt,
                $notes ?: null,
                $currentUser->getId()
            );
            $count++;
        }

        ViewHelper::flash('success', "{$count} Soll-Stunden wurden aktualisiert.");
        return $this->redirect($response, '/admin/targets?year=' . $year);
    }
}
