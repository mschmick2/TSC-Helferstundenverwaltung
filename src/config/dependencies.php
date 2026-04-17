<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\CategoryController;
use App\Controllers\DashboardController;
use App\Controllers\EventAdminController;
use App\Controllers\EventTemplateController;
use App\Controllers\MemberEventController;
use App\Controllers\OrganizerEventController;
use App\Controllers\ReportController;
use App\Controllers\TargetHoursController;
use App\Controllers\UserController;
use App\Controllers\WorkEntryController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RoleMiddleware;
use App\Repositories\AuditRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\DialogReadStatusRepository;
use App\Repositories\DialogRepository;
use App\Repositories\EventOrganizerRepository;
use App\Repositories\EventRepository;
use App\Repositories\EventTaskAssignmentRepository;
use App\Repositories\EventTaskRepository;
use App\Repositories\EventTemplateRepository;
use App\Repositories\ReportRepository;
use App\Repositories\SessionRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkEntryRepository;
use App\Repositories\YearlyTargetRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\CsvExportService;
use App\Services\EmailService;
use App\Services\ImportService;
use App\Services\PdfService;
use App\Services\RateLimitService;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Services\TargetHoursService;
use App\Services\TotpService;
use App\Services\WorkflowService;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return [
    // =========================================================================
    // Settings
    // =========================================================================
    'settings' => function (): array {
        $config = require __DIR__ . '/config.php';
        return $config;
    },

    // =========================================================================
    // PDO (Datenbank-Verbindung)
    // =========================================================================
    PDO::class => function (ContainerInterface $c): PDO {
        $db = $c->get('settings')['database'];

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['name'],
            $db['charset']
        );

        return new PDO($dsn, $db['user'], $db['password'], $db['options']);
    },

    // =========================================================================
    // Logger
    // =========================================================================
    LoggerInterface::class => function (ContainerInterface $c): LoggerInterface {
        $settings = $c->get('settings');
        $logPath = $settings['paths']['logs'] . '/app.log';
        $level = Level::fromName($settings['logging']['level'] ?? 'warning');

        $logger = new Logger('vaes');
        $logger->pushHandler(new StreamHandler($logPath, $level));

        return $logger;
    },

    // =========================================================================
    // Repositories
    // =========================================================================
    UserRepository::class => function (ContainerInterface $c): UserRepository {
        return new UserRepository($c->get(PDO::class));
    },

    SessionRepository::class => function (ContainerInterface $c): SessionRepository {
        return new SessionRepository($c->get(PDO::class));
    },

    CategoryRepository::class => function (ContainerInterface $c): CategoryRepository {
        return new CategoryRepository($c->get(PDO::class));
    },

    WorkEntryRepository::class => function (ContainerInterface $c): WorkEntryRepository {
        return new WorkEntryRepository($c->get(PDO::class));
    },

    DialogRepository::class => function (ContainerInterface $c): DialogRepository {
        return new DialogRepository($c->get(PDO::class));
    },

    DialogReadStatusRepository::class => function (ContainerInterface $c): DialogReadStatusRepository {
        return new DialogReadStatusRepository($c->get(PDO::class));
    },

    SettingsRepository::class => function (ContainerInterface $c): SettingsRepository {
        return new SettingsRepository($c->get(PDO::class));
    },

    AuditRepository::class => function (ContainerInterface $c): AuditRepository {
        return new AuditRepository($c->get(PDO::class));
    },

    YearlyTargetRepository::class => function (ContainerInterface $c): YearlyTargetRepository {
        return new YearlyTargetRepository($c->get(PDO::class));
    },

    ReportRepository::class => function (ContainerInterface $c): ReportRepository {
        return new ReportRepository($c->get(PDO::class));
    },

    // --- Modul 6: Events -----------------------------------------------------
    EventRepository::class => function (ContainerInterface $c): EventRepository {
        return new EventRepository($c->get(PDO::class));
    },

    EventOrganizerRepository::class => function (ContainerInterface $c): EventOrganizerRepository {
        return new EventOrganizerRepository($c->get(PDO::class));
    },

    EventTaskRepository::class => function (ContainerInterface $c): EventTaskRepository {
        return new EventTaskRepository($c->get(PDO::class));
    },

    EventTaskAssignmentRepository::class => function (ContainerInterface $c): EventTaskAssignmentRepository {
        return new EventTaskAssignmentRepository($c->get(PDO::class));
    },

    EventTemplateRepository::class => function (ContainerInterface $c): EventTemplateRepository {
        return new EventTemplateRepository($c->get(PDO::class));
    },

    // --- Modul 6 I2: Event-Assignment-Service --------------------------------
    \App\Services\EventAssignmentService::class => function (ContainerInterface $c): \App\Services\EventAssignmentService {
        return new \App\Services\EventAssignmentService(
            $c->get(EventRepository::class),
            $c->get(EventTaskRepository::class),
            $c->get(EventTaskAssignmentRepository::class),
            $c->get(EventOrganizerRepository::class),
            $c->get(AuditService::class),
            $c->get(UserRepository::class)
        );
    },

    // --- Modul 6 I3: Event-Completion-Service --------------------------------
    \App\Services\EventCompletionService::class => function (ContainerInterface $c): \App\Services\EventCompletionService {
        return new \App\Services\EventCompletionService(
            $c->get(PDO::class),
            $c->get(EventRepository::class),
            $c->get(EventTaskRepository::class),
            $c->get(EventTaskAssignmentRepository::class),
            $c->get(WorkEntryRepository::class),
            $c->get(UserRepository::class),
            $c->get(AuditService::class)
        );
    },

    // =========================================================================
    // Services
    // =========================================================================
    AuditService::class => function (ContainerInterface $c): AuditService {
        return new AuditService($c->get(PDO::class));
    },

    EmailService::class => function (ContainerInterface $c): EmailService {
        $settings = $c->get('settings');
        return new EmailService($settings['mail'], $c->get(LoggerInterface::class));
    },

    TotpService::class => function (ContainerInterface $c): TotpService {
        $settings = $c->get('settings');
        return new TotpService(
            $c->get(PDO::class),
            $settings['2fa']
        );
    },

    AuthService::class => function (ContainerInterface $c): AuthService {
        $settings = $c->get('settings');
        return new AuthService(
            $c->get(UserRepository::class),
            $c->get(SessionRepository::class),
            $c->get(AuditService::class),
            $settings['security']
        );
    },

    WorkflowService::class => function (ContainerInterface $c): WorkflowService {
        $settings = $c->get('settings');
        return new WorkflowService(
            $c->get(WorkEntryRepository::class),
            $c->get(DialogRepository::class),
            $c->get(AuditService::class),
            $c->get(EmailService::class),
            $c->get(UserRepository::class),
            $c->get(LoggerInterface::class),
            $settings['app']['url'] ?? ''
        );
    },

    SettingsService::class => function (ContainerInterface $c): SettingsService {
        return new SettingsService(
            $c->get(SettingsRepository::class),
            $c->get(AuditService::class)
        );
    },

    ImportService::class => function (ContainerInterface $c): ImportService {
        $settings = $c->get('settings');
        return new ImportService(
            $c->get(UserRepository::class),
            $c->get(SettingsService::class),
            $c->get(EmailService::class),
            $c->get(AuditService::class),
            $c->get(PDO::class),
            $settings['app']['url'] ?? ''
        );
    },

    TargetHoursService::class => function (ContainerInterface $c): TargetHoursService {
        return new TargetHoursService(
            $c->get(YearlyTargetRepository::class),
            $c->get(SettingsService::class),
            $c->get(AuditService::class)
        );
    },

    ReportService::class => function (ContainerInterface $c): ReportService {
        return new ReportService(
            $c->get(ReportRepository::class),
            $c->get(AuditService::class)
        );
    },

    PdfService::class => function (ContainerInterface $c): PdfService {
        return new PdfService($c->get(SettingsService::class));
    },

    CsvExportService::class => function (): CsvExportService {
        return new CsvExportService();
    },

    RateLimitService::class => function (ContainerInterface $c): RateLimitService {
        return new RateLimitService($c->get(PDO::class));
    },

    // =========================================================================
    // Middleware
    // =========================================================================
    AuthMiddleware::class => function (ContainerInterface $c): AuthMiddleware {
        $settings = $c->get('settings');
        return new AuthMiddleware(
            $c->get(AuthService::class),
            $c->get(SessionRepository::class),
            $c->get(UserRepository::class),
            (int) ($settings['session']['lifetime'] ?? 1800),
            (bool) ($settings['security']['require_2fa'] ?? true)
        );
    },

    CsrfMiddleware::class => function (): CsrfMiddleware {
        return new CsrfMiddleware();
    },

    // =========================================================================
    // Controllers
    // =========================================================================
    AuthController::class => function (ContainerInterface $c): AuthController {
        $settings = $c->get('settings');
        return new AuthController(
            $c->get(AuthService::class),
            $c->get(TotpService::class),
            $c->get(EmailService::class),
            $c->get(AuditService::class),
            $c->get(UserRepository::class),
            $c->get(RateLimitService::class),
            $settings
        );
    },

    DashboardController::class => function (ContainerInterface $c): DashboardController {
        return new DashboardController(
            $c->get(TargetHoursService::class),
            $c->get(DialogReadStatusRepository::class),
            $c->get(WorkEntryRepository::class),
            $c->get('settings')
        );
    },

    WorkEntryController::class => function (ContainerInterface $c): WorkEntryController {
        return new WorkEntryController(
            $c->get(WorkEntryRepository::class),
            $c->get(CategoryRepository::class),
            $c->get(DialogRepository::class),
            $c->get(DialogReadStatusRepository::class),
            $c->get(WorkflowService::class),
            $c->get(AuditService::class),
            $c->get(EmailService::class),
            $c->get(UserRepository::class),
            $c->get(SettingsService::class),
            $c->get(LoggerInterface::class),
            $c->get('settings')
        );
    },

    CategoryController::class => function (ContainerInterface $c): CategoryController {
        return new CategoryController(
            $c->get(CategoryRepository::class),
            $c->get(AuditService::class),
            $c->get('settings')
        );
    },

    UserController::class => function (ContainerInterface $c): UserController {
        return new UserController(
            $c->get(UserRepository::class),
            $c->get(ImportService::class),
            $c->get(EmailService::class),
            $c->get(AuditService::class),
            $c->get(SettingsService::class),
            $c->get('settings')
        );
    },

    AdminController::class => function (ContainerInterface $c): AdminController {
        return new AdminController(
            $c->get(SettingsRepository::class),
            $c->get(SettingsService::class),
            $c->get(AuditService::class),
            $c->get(EmailService::class),
            $c->get('settings')
        );
    },

    AuditController::class => function (ContainerInterface $c): AuditController {
        return new AuditController(
            $c->get(AuditRepository::class),
            $c->get(UserRepository::class),
            $c->get('settings')
        );
    },

    TargetHoursController::class => function (ContainerInterface $c): TargetHoursController {
        return new TargetHoursController(
            $c->get(TargetHoursService::class),
            $c->get(UserRepository::class),
            $c->get(AuditService::class),
            $c->get('settings')
        );
    },

    ReportController::class => function (ContainerInterface $c): ReportController {
        return new ReportController(
            $c->get(ReportService::class),
            $c->get(PdfService::class),
            $c->get(CsvExportService::class),
            $c->get(CategoryRepository::class),
            $c->get(SettingsService::class),
            $c->get('settings')
        );
    },

    // --- Modul 6: Event-Controllers ------------------------------------------
    EventAdminController::class => function (ContainerInterface $c): EventAdminController {
        return new EventAdminController(
            $c->get(EventRepository::class),
            $c->get(EventTaskRepository::class),
            $c->get(EventOrganizerRepository::class),
            $c->get(CategoryRepository::class),
            $c->get(UserRepository::class),
            $c->get(AuditService::class),
            $c->get(\App\Services\EventCompletionService::class),
            $c->get('settings')
        );
    },

    EventTemplateController::class => function (ContainerInterface $c): EventTemplateController {
        return new EventTemplateController(
            $c->get(EventTemplateRepository::class),
            $c->get(CategoryRepository::class),
            $c->get(AuditService::class),
            $c->get('settings')
        );
    },

    // --- Modul 6 I2: Mitglieder- + Organisator-Controller --------------------
    MemberEventController::class => function (ContainerInterface $c): MemberEventController {
        return new MemberEventController(
            $c->get(EventRepository::class),
            $c->get(EventTaskRepository::class),
            $c->get(EventTaskAssignmentRepository::class),
            $c->get(EventOrganizerRepository::class),
            $c->get(\App\Services\EventAssignmentService::class),
            $c->get('settings')
        );
    },

    OrganizerEventController::class => function (ContainerInterface $c): OrganizerEventController {
        return new OrganizerEventController(
            $c->get(EventRepository::class),
            $c->get(EventTaskRepository::class),
            $c->get(EventTaskAssignmentRepository::class),
            $c->get(EventOrganizerRepository::class),
            $c->get(UserRepository::class),
            $c->get(\App\Services\EventAssignmentService::class),
            $c->get('settings')
        );
    },
];
