<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessRuleException;
use App\Helpers\ViewHelper;
use App\Repositories\EventOrganizerRepository;
use App\Repositories\EventRepository;
use App\Repositories\EventTaskAssignmentRepository;
use App\Repositories\EventTaskRepository;
use App\Repositories\UserRepository;
use App\Services\EventAssignmentService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Organisator-Sicht (Modul 6 I2).
 *
 * Zeigt Review-Queue mit vorgeschlagenen Zeitfenstern und angefragten Stornos;
 * Aktionen delegieren an EventAssignmentService (mit Self-Approval-Guard).
 */
class OrganizerEventController extends BaseController
{
    public function __construct(
        private EventRepository $eventRepo,
        private EventTaskRepository $taskRepo,
        private EventTaskAssignmentRepository $assignmentRepo,
        private EventOrganizerRepository $organizerRepo,
        private UserRepository $userRepo,
        private EventAssignmentService $assignmentService,
        private array $settings
    ) {
    }

    // =========================================================================
    // GET /organizer/events  -- Eigene Events + Review-Queue
    // =========================================================================

    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userId = (int) $user->getId();

        $events = $this->eventRepo->findForOrganizer($userId);
        $pendingReviews = $this->assignmentRepo->findPendingReviewsForOrganizer($userId);

        // Kontext-Map fuer Review-Items (Task, Event, Antragsteller)
        $reviewContext = [];
        foreach ($pendingReviews as $a) {
            $task = $this->taskRepo->findById($a->getTaskId());
            $event = $task !== null ? $this->eventRepo->findById($task->getEventId()) : null;
            $assignee = $this->userRepo->findById($a->getUserId());
            $replacement = $a->getReplacementSuggestedUserId() !== null
                ? $this->userRepo->findById((int) $a->getReplacementSuggestedUserId())
                : null;
            $reviewContext[$a->getId()] = [
                'task' => $task,
                'event' => $event,
                'assignee' => $assignee,
                'replacement' => $replacement,
            ];
        }

        // Sachstand pro Event: Task-Belegung (nur Aufgaben, ohne Beigaben)
        $eventSummaries = [];
        foreach ($events as $event) {
            $tasks = $this->taskRepo->findByEvent((int) $event->getId());
            $taskRows = [];
            $totalTarget = 0;
            $totalFilled = 0;
            $hasUnlimited = false;

            foreach ($tasks as $task) {
                if ($task->isContribution()) {
                    continue;
                }
                $filled = $this->assignmentRepo->countActiveByTask((int) $task->getId());
                $target = $task->getCapacityTarget();
                $mode = $task->getCapacityMode();

                if ($mode === \App\Models\EventTask::CAP_UNBEGRENZT) {
                    $open = null;
                    $hasUnlimited = true;
                } elseif ($target !== null) {
                    $open = max(0, $target - $filled);
                    $totalTarget += $target;
                } else {
                    $open = null;
                }
                $totalFilled += $filled;

                $taskRows[] = [
                    'task'   => $task,
                    'filled' => $filled,
                    'target' => $target,
                    'mode'   => $mode,
                    'open'   => $open,
                ];
            }

            $totalOpen = max(0, $totalTarget - $totalFilled);
            $percentage = $totalTarget > 0
                ? (int) round(min(100, ($totalFilled / $totalTarget) * 100))
                : 0;

            $eventSummaries[(int) $event->getId()] = [
                'tasks'         => $taskRows,
                'total_target'  => $totalTarget,
                'total_filled'  => $totalFilled,
                'total_open'    => $totalOpen,
                'has_unlimited' => $hasUnlimited,
                'percentage'    => $percentage,
            ];
        }

        return $this->render($response, 'organizer/events/index', [
            'title' => 'Als Organisator',
            'user' => $user,
            'settings' => $this->settings,
            'events' => $events,
            'eventSummaries' => $eventSummaries,
            'pendingReviews' => $pendingReviews,
            'reviewContext' => $reviewContext,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Als Organisator'],
            ],
        ]);
    }

    // =========================================================================
    // POST /organizer/assignments/{id}/approve-time
    // =========================================================================

    public function approveTime(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];

        try {
            $this->assignmentService->approveTime($id, (int) $user->getId());
            ViewHelper::flash('success', 'Zeitfenster bestaetigt.');
        } catch (AuthorizationException | BusinessRuleException $e) {
            ViewHelper::flash('danger', $e->getMessage());
        }

        return $this->redirect($response, '/organizer/events');
    }

    // =========================================================================
    // POST /organizer/assignments/{id}/reject-time
    // =========================================================================

    public function rejectTime(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];
        $data = (array) $request->getParsedBody();
        $reason = trim((string) ($data['reason'] ?? ''));

        try {
            $this->assignmentService->rejectTime($id, (int) $user->getId(), $reason);
            ViewHelper::flash('success', 'Zeitfenster abgelehnt.');
        } catch (AuthorizationException | BusinessRuleException $e) {
            ViewHelper::flash('danger', $e->getMessage());
        }

        return $this->redirect($response, '/organizer/events');
    }

    // =========================================================================
    // POST /organizer/assignments/{id}/approve-cancel
    // =========================================================================

    public function approveCancel(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];

        try {
            $this->assignmentService->approveCancellation($id, (int) $user->getId());
            ViewHelper::flash('success', 'Storno freigegeben.');
        } catch (AuthorizationException | BusinessRuleException $e) {
            ViewHelper::flash('danger', $e->getMessage());
        }

        return $this->redirect($response, '/organizer/events');
    }

    // =========================================================================
    // POST /organizer/assignments/{id}/reject-cancel
    // =========================================================================

    public function rejectCancel(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $this->routeArgs($request)['id'];
        $data = (array) $request->getParsedBody();
        $reason = trim((string) ($data['reason'] ?? ''));

        try {
            $this->assignmentService->rejectCancellation($id, (int) $user->getId(), $reason);
            ViewHelper::flash('success', 'Storno-Anfrage abgelehnt.');
        } catch (AuthorizationException | BusinessRuleException $e) {
            ViewHelper::flash('danger', $e->getMessage());
        }

        return $this->redirect($response, '/organizer/events');
    }
}
