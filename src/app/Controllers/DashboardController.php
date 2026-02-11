<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use App\Repositories\DialogReadStatusRepository;
use App\Services\TargetHoursService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller für das Dashboard
 */
class DashboardController extends BaseController
{
    public function __construct(
        private TargetHoursService $targetHoursService,
        private DialogReadStatusRepository $dialogReadStatusRepo,
        private array $settings
    ) {
    }

    /**
     * API: Anzahl ungelesener Dialog-Nachrichten (JSON)
     */
    public function unreadCount(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $unread = $this->dialogReadStatusRepo->findUnreadDialogsForUser(
            $user->getId(),
            $user->canReview()
        );
        return $this->json($response, ['count' => count($unread)])
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withHeader('Pragma', 'no-cache');
    }

    /**
     * Dashboard anzeigen
     */
    public function index(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');

        $data = [
            'user' => $user,
            'title' => 'Dashboard',
            'settings' => $this->settings,
            'targetHoursEnabled' => $this->targetHoursService->isEnabled(),
            'targetComparison' => null,
            'unreadDialogs' => $this->dialogReadStatusRepo->findUnreadDialogsForUser($user->getId(), $user->canReview()),
        ];

        if ($data['targetHoursEnabled']) {
            $data['targetComparison'] = $this->targetHoursService->getUserComparison(
                $user->getId(),
                (int) date('Y')
            );
        }

        return $this->render($response, 'dashboard/index', $data);
    }
}
