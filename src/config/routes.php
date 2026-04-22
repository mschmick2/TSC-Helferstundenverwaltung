<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\CategoryController;
use App\Controllers\CronController;
use App\Controllers\DashboardController;
use App\Controllers\EventAdminController;
use App\Controllers\EventTemplateController;
use App\Controllers\IcalController;
use App\Controllers\MemberEventController;
use App\Controllers\OrganizerEventController;
use App\Controllers\ReportController;
use App\Controllers\TargetHoursController;
use App\Controllers\UserController;
use App\Controllers\WorkEntryController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\OpportunisticSchedulerMiddleware;
use App\Middleware\RoleMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    // =========================================================================
    // Öffentliche Routen (kein Login erforderlich)
    // =========================================================================
    $app->group('', function (RouteCollectorProxy $group) {
        // Login
        $group->get('/login', [AuthController::class, 'showLogin']);
        $group->post('/login', [AuthController::class, 'login']);

        // 2FA
        $group->get('/2fa', [AuthController::class, 'show2fa']);
        $group->post('/2fa', [AuthController::class, 'verify2fa']);

        // Passwort-Setup (Einladungslink)
        $group->get('/setup-password/{token}', [AuthController::class, 'showSetupPassword']);
        $group->post('/setup-password/{token}', [AuthController::class, 'setupPassword']);

        // Passwort vergessen
        $group->get('/forgot-password', [AuthController::class, 'showForgotPassword']);
        $group->post('/forgot-password', [AuthController::class, 'requestReset']);

        // Passwort zurücksetzen
        $group->get('/reset-password/{token}', [AuthController::class, 'showResetPassword']);
        $group->post('/reset-password/{token}', [AuthController::class, 'resetPassword']);

        // iCal-Subscription-Feed (Token-Auth, KEINE Session)
        $group->get('/ical/subscribe/{token:[a-f0-9]{64}}', [IcalController::class, 'subscribe']);
    })->add(CsrfMiddleware::class);

    // =========================================================================
    // Cron-Pinger (Strato-Cron-Ersatz, eigene Token-Auth, KEIN CSRF, KEINE Session)
    // =========================================================================
    $app->post('/cron/run', [CronController::class, 'run']);

    // =========================================================================
    // Geschützte Routen (Login erforderlich)
    // =========================================================================
    $app->group('', function (RouteCollectorProxy $group) {
        // Dashboard — opportunistischer Scheduler-Trigger (Cron-Backup)
        $group->get('/', [DashboardController::class, 'index'])
            ->add(OpportunisticSchedulerMiddleware::class);
        $group->get('/dashboard', [DashboardController::class, 'index'])
            ->add(OpportunisticSchedulerMiddleware::class);

        // API: Ungelesene Dialog-Nachrichten Anzahl
        $group->get('/api/unread-dialog-count', [DashboardController::class, 'unreadCount']);

        // Logout
        $group->get('/logout', [AuthController::class, 'logout']);

        // 2FA-Einrichtung (nach Login, wenn noch nicht eingerichtet)
        $group->get('/2fa-setup', [AuthController::class, 'show2faSetup']);
        $group->post('/2fa-setup', [AuthController::class, 'setup2fa']);

        // =====================================================================
        // Arbeitsstunden-Einträge
        // =====================================================================
        $group->get('/entries', [WorkEntryController::class, 'index']);
        $group->get('/entries/create', [WorkEntryController::class, 'create']);
        $group->post('/entries', [WorkEntryController::class, 'store']);
        $group->get('/entries/{id:[0-9]+}', [WorkEntryController::class, 'show']);
        $group->get('/entries/{id:[0-9]+}/edit', [WorkEntryController::class, 'edit']);
        $group->post('/entries/{id:[0-9]+}', [WorkEntryController::class, 'update']);
        $group->post('/entries/{id:[0-9]+}/delete', [WorkEntryController::class, 'delete']);

        // Modul 7 I1: Pessimistic-Lock-AJAX
        $group->post('/entries/{id:[0-9]+}/lock/heartbeat', [WorkEntryController::class, 'lockHeartbeat']);
        $group->post('/entries/{id:[0-9]+}/lock/release', [WorkEntryController::class, 'lockRelease']);
        $group->get('/entries/{id:[0-9]+}/lock/status', [WorkEntryController::class, 'lockStatus']);

        // Workflow-Aktionen (Eigentümer)
        $group->post('/entries/{id:[0-9]+}/submit', [WorkEntryController::class, 'submit']);
        $group->post('/entries/{id:[0-9]+}/withdraw', [WorkEntryController::class, 'withdraw']);
        $group->post('/entries/{id:[0-9]+}/cancel', [WorkEntryController::class, 'cancel']);
        $group->post('/entries/{id:[0-9]+}/reactivate', [WorkEntryController::class, 'reactivate']);

        // Dialog-Nachrichten
        $group->post('/entries/{id:[0-9]+}/message', [WorkEntryController::class, 'addMessage']);

        // =====================================================================
        // Reports (rollenbasierte Datenfilterung im Service)
        // =====================================================================
        $group->get('/reports', [ReportController::class, 'index']);
        $group->get('/reports/export/pdf', [ReportController::class, 'exportPdf']);
        $group->get('/reports/export/csv', [ReportController::class, 'exportCsv']);
    })->add(CsrfMiddleware::class)->add(AuthMiddleware::class);

    // =========================================================================
    // Prüfer-Routen (Prüfer + Admin erforderlich)
    // =========================================================================
    $app->group('', function (RouteCollectorProxy $group) {
        $group->get('/review', [WorkEntryController::class, 'reviewList']);
        $group->post('/entries/{id:[0-9]+}/approve', [WorkEntryController::class, 'approve']);
        $group->post('/entries/{id:[0-9]+}/reject', [WorkEntryController::class, 'reject']);
        $group->post('/entries/{id:[0-9]+}/return', [WorkEntryController::class, 'returnForRevision']);
        $group->post('/entries/{id:[0-9]+}/return-to-draft', [WorkEntryController::class, 'returnToDraft']);
        $group->post('/entries/{id:[0-9]+}/correct', [WorkEntryController::class, 'correct']);
    })
        ->add(new RoleMiddleware(['pruefer', 'administrator']))
        ->add(CsrfMiddleware::class)
        ->add(AuthMiddleware::class);

    // =========================================================================
    // Admin-Routen (Administrator erforderlich)
    // =========================================================================
    $app->group('/admin', function (RouteCollectorProxy $group) {
        // Kategorien
        $group->get('/categories', [CategoryController::class, 'index']);
        $group->post('/categories', [CategoryController::class, 'store']);
        $group->post('/categories/{id:[0-9]+}', [CategoryController::class, 'update']);
        $group->post('/categories/{id:[0-9]+}/deactivate', [CategoryController::class, 'deactivate']);
        $group->post('/categories/{id:[0-9]+}/activate', [CategoryController::class, 'activate']);
        $group->post('/categories/{id:[0-9]+}/delete', [CategoryController::class, 'delete']);

        // Benutzer
        $group->get('/users', [UserController::class, 'index']);
        $group->get('/users/create', [UserController::class, 'showCreate']);
        $group->post('/users/create', [UserController::class, 'storeUser']);
        $group->get('/users/import', [UserController::class, 'showImport']);
        $group->post('/users/import', [UserController::class, 'import']);
        $group->get('/users/import-result', [UserController::class, 'importResult']);
        $group->get('/users/{id:[0-9]+}', [UserController::class, 'show']);
        $group->post('/users/{id:[0-9]+}/roles', [UserController::class, 'updateRoles']);
        $group->post('/users/{id:[0-9]+}/reinvite', [UserController::class, 'reinvite']);
        $group->post('/users/{id:[0-9]+}/deactivate', [UserController::class, 'deactivate']);
        $group->post('/users/{id:[0-9]+}/activate', [UserController::class, 'activate']);
        $group->post('/users/{id:[0-9]+}/delete', [UserController::class, 'delete']);
        $group->post('/users/{id:[0-9]+}/unlock', [UserController::class, 'unlock']);

        // Einstellungen
        $group->get('/settings', [AdminController::class, 'settings']);
        $group->post('/settings', [AdminController::class, 'updateSettings']);
        $group->post('/settings/test-email', [AdminController::class, 'testEmail']);
        $group->post('/settings/cron-token', [AdminController::class, 'rotateCronToken']);

        // Audit-Trail
        $group->get('/audit', [AuditController::class, 'index']);
        $group->get('/audit/{id:[0-9]+}', [AuditController::class, 'show']);

        // Soll-Stunden
        $group->get('/targets', [TargetHoursController::class, 'index']);
        $group->get('/targets/{userId:[0-9]+}', [TargetHoursController::class, 'editUser']);
        $group->post('/targets/{userId:[0-9]+}', [TargetHoursController::class, 'updateUser']);
        $group->post('/targets/bulk', [TargetHoursController::class, 'bulkUpdate']);
    })
        ->add(new RoleMiddleware(['administrator']))
        ->add(CsrfMiddleware::class)
        ->add(AuthMiddleware::class);

    // =========================================================================
    // Event-Admin-Routen (event_admin + administrator, Modul 6 I1)
    // =========================================================================
    $app->group('/admin', function (RouteCollectorProxy $group) {
        // Events
        $group->get('/events', [EventAdminController::class, 'index']);
        $group->get('/events/create', [EventAdminController::class, 'create']);
        $group->post('/events', [EventAdminController::class, 'store']);
        $group->get('/events/{id:[0-9]+}', [EventAdminController::class, 'show']);
        $group->get('/events/{id:[0-9]+}/edit', [EventAdminController::class, 'edit']);
        $group->post('/events/{id:[0-9]+}', [EventAdminController::class, 'update']);
        $group->post('/events/{id:[0-9]+}/publish', [EventAdminController::class, 'publish']);
        $group->post('/events/{id:[0-9]+}/cancel', [EventAdminController::class, 'cancel']);
        $group->post('/events/{id:[0-9]+}/complete', [EventAdminController::class, 'complete']);
        $group->post('/events/{id:[0-9]+}/delete', [EventAdminController::class, 'delete']);

        // Event-Tasks
        $group->post('/events/{id:[0-9]+}/tasks', [EventAdminController::class, 'addTask']);
        $group->post('/events/{eventId:[0-9]+}/tasks/{taskId:[0-9]+}/delete',
            [EventAdminController::class, 'deleteTask']);

        // Event-Templates (I1: CRUD; I4: Task-Editor, Versionierung, Ableitung)
        $group->get('/event-templates', [EventTemplateController::class, 'index']);
        $group->post('/event-templates', [EventTemplateController::class, 'store']);
        $group->get('/event-templates/{id:[0-9]+}', [EventTemplateController::class, 'show']);
        $group->get('/event-templates/{id:[0-9]+}/edit', [EventTemplateController::class, 'edit']);
        $group->post('/event-templates/{id:[0-9]+}/delete', [EventTemplateController::class, 'delete']);
        $group->post('/event-templates/{id:[0-9]+}/tasks', [EventTemplateController::class, 'addTask']);
        $group->post('/event-templates/{id:[0-9]+}/tasks/{taskId:[0-9]+}/update',
            [EventTemplateController::class, 'updateTask']);
        $group->post('/event-templates/{id:[0-9]+}/tasks/{taskId:[0-9]+}/delete',
            [EventTemplateController::class, 'deleteTask']);
        $group->post('/event-templates/{id:[0-9]+}/save-as-new-version',
            [EventTemplateController::class, 'saveAsNewVersion']);
        $group->get('/event-templates/{id:[0-9]+}/derive',
            [EventTemplateController::class, 'deriveForm']);
        $group->post('/event-templates/{id:[0-9]+}/derive',
            [EventTemplateController::class, 'deriveStore']);
    })
        ->add(new RoleMiddleware(['event_admin', 'administrator']))
        ->add(CsrfMiddleware::class)
        ->add(AuthMiddleware::class);

    // =========================================================================
    // Mitglieder-Events-Routen (Modul 6 I2, alle angemeldeten User)
    // =========================================================================
    $app->group('', function (RouteCollectorProxy $group) {
        // Kalender-Ansichten (I5) — muessen VOR /events/{id} stehen
        $group->get('/events/calendar', [MemberEventController::class, 'calendar']);
        $group->get('/my-events/calendar', [MemberEventController::class, 'myCalendar']);

        // API fuer FullCalendar (JSON-Feed)
        $group->get('/api/events/calendar', [MemberEventController::class, 'calendarJson']);
        $group->get('/api/my-events/calendar', [MemberEventController::class, 'myCalendarJson']);

        // iCal-Settings + Token-Regenerate (authenticated)
        $group->get('/my-events/ical', [MemberEventController::class, 'icalSettings']);
        $group->post('/my-events/ical/regenerate', [MemberEventController::class, 'regenerateIcalToken']);

        // Event-Einzel-iCal-Download
        $group->get('/events/{id:[0-9]+}.ics', [IcalController::class, 'downloadEvent']);

        $group->get('/events', [MemberEventController::class, 'index']);
        $group->get('/events/{id:[0-9]+}', [MemberEventController::class, 'show']);
        $group->post(
            '/events/{eventId:[0-9]+}/tasks/{taskId:[0-9]+}/assign',
            [MemberEventController::class, 'assign']
        );

        $group->get('/my-events', [MemberEventController::class, 'myAssignments']);
        $group->post(
            '/my-events/assignments/{id:[0-9]+}/withdraw',
            [MemberEventController::class, 'withdraw']
        );
        $group->post(
            '/my-events/assignments/{id:[0-9]+}/cancel',
            [MemberEventController::class, 'requestCancellation']
        );
    })
        ->add(CsrfMiddleware::class)
        ->add(AuthMiddleware::class);

    // =========================================================================
    // Organisator-Routen (Modul 6 I2, jeder angemeldete User kann - Owner-Check
    // erfolgt serverseitig via EventAssignmentService/Guards)
    // =========================================================================
    $app->group('/organizer', function (RouteCollectorProxy $group) {
        $group->get('/events', [OrganizerEventController::class, 'index']);
        $group->post(
            '/assignments/{id:[0-9]+}/approve-time',
            [OrganizerEventController::class, 'approveTime']
        );
        $group->post(
            '/assignments/{id:[0-9]+}/reject-time',
            [OrganizerEventController::class, 'rejectTime']
        );
        $group->post(
            '/assignments/{id:[0-9]+}/approve-cancel',
            [OrganizerEventController::class, 'approveCancel']
        );
        $group->post(
            '/assignments/{id:[0-9]+}/reject-cancel',
            [OrganizerEventController::class, 'rejectCancel']
        );
    })
        ->add(CsrfMiddleware::class)
        ->add(AuthMiddleware::class);

    // =========================================================================
    // Auditor-Routen (Auditor + Admin)
    // =========================================================================
    $app->group('/audit', function (RouteCollectorProxy $group) {
        $group->get('', [AuditController::class, 'index']);
        $group->get('/{id:[0-9]+}', [AuditController::class, 'show']);
    })
        ->add(new RoleMiddleware(['auditor', 'administrator']))
        ->add(CsrfMiddleware::class)
        ->add(AuthMiddleware::class);
};
