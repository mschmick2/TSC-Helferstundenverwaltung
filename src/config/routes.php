<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\CategoryController;
use App\Controllers\DashboardController;
use App\Controllers\ReportController;
use App\Controllers\TargetHoursController;
use App\Controllers\UserController;
use App\Controllers\WorkEntryController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
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
    })->add(CsrfMiddleware::class);

    // =========================================================================
    // Geschützte Routen (Login erforderlich)
    // =========================================================================
    $app->group('', function (RouteCollectorProxy $group) {
        // Dashboard
        $group->get('/', [DashboardController::class, 'index']);
        $group->get('/dashboard', [DashboardController::class, 'index']);

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
        $group->post('/categories/reorder', [CategoryController::class, 'reorder']);

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

        // Einstellungen
        $group->get('/settings', [AdminController::class, 'settings']);
        $group->post('/settings', [AdminController::class, 'updateSettings']);
        $group->post('/settings/test-email', [AdminController::class, 'testEmail']);

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
