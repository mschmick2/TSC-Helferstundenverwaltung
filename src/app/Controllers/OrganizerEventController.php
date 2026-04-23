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
use App\Services\SettingsService;
use App\Services\TaskTreeAggregator;
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
        private array $settings,
        private ?TaskTreeAggregator $treeAggregator = null,
        private ?SettingsService $settingsService = null
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

        // I7e-A Phase 2c: Editor-Link in der Event-Card nur anzeigen, wenn
        // das Tree-Editor-Feature-Flag aktiv ist. Sonst bleibt der Link in
        // Strato-Produktion (flag=0) unsichtbar.
        $treeEditorEnabled = $this->settingsService !== null
            && $this->settingsService->getString('events.tree_editor_enabled', '0') === '1';

        return $this->render($response, 'organizer/events/index', [
            'title' => 'Als Organisator',
            'user' => $user,
            'settings' => $this->settings,
            'events' => $events,
            'eventSummaries' => $eventSummaries,
            'pendingReviews' => $pendingReviews,
            'reviewContext' => $reviewContext,
            'treeEditorEnabled' => $treeEditorEnabled,
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

    // =========================================================================
    // GET /organizer/events/{eventId}/tasks-by-date  (Modul 6 I7b4)
    //
    // Chronologische Leaves-Liste fuer Organisatoren des Events. Read-Only;
    // Task-Titel werden NICHT als Link gerendert, weil die Admin-Detail-Seite
    // durch RoleMiddleware event_admin-gebunden ist und Organisatoren dort
    // einen 403 bekommen wuerden. Der non-modale Organisator-Editor folgt
    // in I7e.
    // =========================================================================

    public function tasksByDate(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $eventId = (int) $this->routeArgs($request)['eventId'];

        // Flag-Gate: hinter events.tree_editor_enabled. Konsistent mit
        // I7b1/I7b2/I7b3.
        if ($this->settingsService === null
            || $this->settingsService->getString('events.tree_editor_enabled', '0') !== '1'
            || $this->treeAggregator === null
        ) {
            return $response->withStatus(404);
        }

        // Owner-Check: Organisator-Group hat keine RoleMiddleware; ein
        // Nicht-Organisator darf das Event nicht sehen. 403 statt 404, damit
        // die Existenz des Events nicht geraten werden muss.
        if (!$this->organizerRepo->isOrganizer($eventId, (int) $user->getId())) {
            return $response->withStatus(403);
        }

        $event = $this->eventRepo->findById($eventId);
        if ($event === null) {
            return $response->withStatus(404);
        }

        $tasks            = $this->taskRepo->findByEvent($eventId);
        $assignmentCounts = $this->assignmentRepo->countActiveByEvent($eventId);
        $flatList         = $this->treeAggregator->flattenToList($tasks, $assignmentCounts);

        // Primary-Sort: start_at asc (nulls last). PHP-8-usort ist stabil —
        // die Depth-First-Baum-Reihenfolge aus flattenToList bleibt als
        // Sekundaer-Sortier-Schluessel erhalten.
        usort($flatList, static function (array $a, array $b): int {
            $aStart = $a['task']->getStartAt();
            $bStart = $b['task']->getStartAt();
            if ($aStart === null && $bStart === null) {
                return 0;
            }
            if ($aStart === null) {
                return 1;
            }
            if ($bStart === null) {
                return -1;
            }
            return strcmp($aStart, $bStart);
        });

        return $this->render($response, 'organizer/events/tasks_by_date', [
            'title' => 'Aufgaben nach Datum — ' . $event->getTitle(),
            'user' => $user,
            'settings' => $this->settings,
            'event' => $event,
            'flatList' => $flatList,
            // Organisator sieht reine Liste, ohne Link auf Admin-Detail-Seite.
            // RoleMiddleware an /admin/events/{id} liefert sonst 403.
            'linkTaskTitles' => false,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Als Organisator', 'url' => '/organizer/events'],
                ['label' => $event->getTitle() . ' — Aufgaben nach Datum'],
            ],
        ]);
    }
}
