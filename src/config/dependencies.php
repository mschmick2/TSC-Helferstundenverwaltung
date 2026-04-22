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
use App\Repositories\AuditRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\DialogReadStatusRepository;
use App\Repositories\DialogRepository;
use App\Repositories\EntryLockRepository;
use App\Repositories\EventOrganizerRepository;
use App\Repositories\EventRepository;
use App\Repositories\EventTaskAssignmentRepository;
use App\Repositories\EventTaskRepository;
use App\Repositories\EventTemplateRepository;
use App\Repositories\ReportRepository;
use App\Repositories\ScheduledJobRepository;
use App\Repositories\SchedulerRunRepository;
use App\Repositories\SessionRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkEntryRepository;
use App\Repositories\YearlyTargetRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\CsvExportService;
use App\Services\EmailService;
use App\Services\EntryLockService;
use App\Services\ImportService;
use App\Services\Jobs\AssignmentInviteHandler;
use App\Services\Jobs\AssignmentReminderHandler;
use App\Services\Jobs\DialogReminderHandler;
use App\Services\Jobs\EventCompletionReminderHandler;
use App\Services\Jobs\EventReminderHandler;
use App\Services\Jobs\JobHandlerRegistry;
use App\Services\NotificationService;
use App\Services\PdfService;
use App\Services\RateLimitService;
use App\Services\ReportService;
use App\Services\SchedulerService;
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
        // Config-Datei via VAES_CONFIG_FILE umschaltbar (Modul 8 E2E).
        $configFile = getenv('VAES_CONFIG_FILE') ?: __DIR__ . '/config.php';
        return require $configFile;
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

    // --- Modul 6 I6: Scheduler-Repositories ----------------------------------
    ScheduledJobRepository::class => function (ContainerInterface $c): ScheduledJobRepository {
        return new ScheduledJobRepository($c->get(PDO::class));
    },

    SchedulerRunRepository::class => function (ContainerInterface $c): SchedulerRunRepository {
        return new SchedulerRunRepository($c->get(PDO::class));
    },

    // --- Modul 7 I1: Entry-Lock-Repository ----------------------------------
    EntryLockRepository::class => function (ContainerInterface $c): EntryLockRepository {
        return new EntryLockRepository($c->get(PDO::class));
    },

    // --- Modul 6 I2: Event-Assignment-Service --------------------------------
    \App\Services\EventAssignmentService::class => function (ContainerInterface $c): \App\Services\EventAssignmentService {
        return new \App\Services\EventAssignmentService(
            $c->get(EventRepository::class),
            $c->get(EventTaskRepository::class),
            $c->get(EventTaskAssignmentRepository::class),
            $c->get(EventOrganizerRepository::class),
            $c->get(AuditService::class),
            $c->get(UserRepository::class),
            $c->get(SchedulerService::class)
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
            $c->get(AuditService::class),
            $c->get(SchedulerService::class)
        );
    },

    // --- Modul 6 I4: Event-Template-Service ----------------------------------
    \App\Services\EventTemplateService::class => function (ContainerInterface $c): \App\Services\EventTemplateService {
        return new \App\Services\EventTemplateService(
            $c->get(PDO::class),
            $c->get(EventTemplateRepository::class),
            $c->get(EventRepository::class),
            $c->get(EventTaskRepository::class),
            $c->get(AuditService::class)
        );
    },

    // --- Modul 6 I7a/I7b1: Aufgabenbaum --------------------------------------
    \App\Services\TaskTreeService::class => function (ContainerInterface $c): \App\Services\TaskTreeService {
        return new \App\Services\TaskTreeService(
            $c->get(PDO::class),
            $c->get(EventTaskRepository::class),
            $c->get(SettingsRepository::class),
            $c->get(AuditService::class)
        );
    },

    \App\Services\TaskTreeAggregator::class => function (): \App\Services\TaskTreeAggregator {
        return new \App\Services\TaskTreeAggregator();
    },

    // --- Modul 6 I5: Kalender + iCal -----------------------------------------
    \App\Services\IcalService::class => function (): \App\Services\IcalService {
        return new \App\Services\IcalService();
    },

    \App\Services\CalendarFeedService::class => function (): \App\Services\CalendarFeedService {
        return new \App\Services\CalendarFeedService();
    },

    // --- Modul 6 I6: Notifications + Scheduler -------------------------------
    NotificationService::class => function (ContainerInterface $c): NotificationService {
        $settings = $c->get('settings');
        return new NotificationService(
            $c->get(EmailService::class),
            (string) ($settings['app']['url'] ?? '')
        );
    },

    EventReminderHandler::class => function (ContainerInterface $c): EventReminderHandler {
        return new EventReminderHandler(
            $c->get(EventRepository::class),
            $c->get(EventTaskAssignmentRepository::class),
            $c->get(UserRepository::class),
            $c->get(NotificationService::class),
            $c->get(LoggerInterface::class)
        );
    },

    AssignmentInviteHandler::class => function (ContainerInterface $c): AssignmentInviteHandler {
        return new AssignmentInviteHandler(
            $c->get(EventTaskAssignmentRepository::class),
            $c->get(EventTaskRepository::class),
            $c->get(EventRepository::class),
            $c->get(UserRepository::class),
            $c->get(NotificationService::class),
            $c->get(LoggerInterface::class)
        );
    },

    AssignmentReminderHandler::class => function (ContainerInterface $c): AssignmentReminderHandler {
        return new AssignmentReminderHandler(
            $c->get(EventTaskAssignmentRepository::class),
            $c->get(EventTaskRepository::class),
            $c->get(EventRepository::class),
            $c->get(UserRepository::class),
            $c->get(NotificationService::class),
            $c->get(LoggerInterface::class)
        );
    },

    DialogReminderHandler::class => function (ContainerInterface $c): DialogReminderHandler {
        return new DialogReminderHandler(
            $c->get(WorkEntryRepository::class),
            $c->get(DialogRepository::class),
            $c->get(UserRepository::class),
            $c->get(NotificationService::class),
            $c->get(LoggerInterface::class)
        );
    },

    EventCompletionReminderHandler::class => function (ContainerInterface $c): EventCompletionReminderHandler {
        return new EventCompletionReminderHandler(
            $c->get(EventRepository::class),
            $c->get(EventOrganizerRepository::class),
            $c->get(UserRepository::class),
            $c->get(NotificationService::class),
            $c->get(LoggerInterface::class)
        );
    },

    JobHandlerRegistry::class => function (ContainerInterface $c): JobHandlerRegistry {
        $registry = new JobHandlerRegistry($c);
        // Mapping job_type -> Handler-Klasse.
        // EventReminderHandler bedient sowohl 24h- als auch 7d-Vorlauf
        // (Unterscheidung kommt aus dem Payload-Feld days_before).
        $registry->register('event_reminder_24h',     EventReminderHandler::class);
        $registry->register('event_reminder_7d',      EventReminderHandler::class);
        $registry->register('assignment_invite',      AssignmentInviteHandler::class);
        $registry->register('assignment_reminder',    AssignmentReminderHandler::class);
        $registry->register('dialog_reminder',        DialogReminderHandler::class);
        $registry->register('event_completion_reminder', EventCompletionReminderHandler::class);
        return $registry;
    },

    SchedulerService::class => function (ContainerInterface $c): SchedulerService {
        return new SchedulerService(
            $c->get(ScheduledJobRepository::class),
            $c->get(SchedulerRunRepository::class),
            $c->get(SettingsRepository::class),
            $c->get(JobHandlerRegistry::class),
            $c->get(LoggerInterface::class),
            $c->get(EntryLockService::class)
        );
    },

    // --- Modul 7 I1: Entry-Lock-Service --------------------------------------
    EntryLockService::class => function (ContainerInterface $c): EntryLockService {
        return new EntryLockService(
            $c->get(EntryLockRepository::class),
            $c->get(UserRepository::class),
            $c->get(SettingsService::class)
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
            $settings['app']['url'] ?? '',
            $c->get(SchedulerService::class),
            $c->get(SettingsService::class)
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

    OpportunisticSchedulerMiddleware::class => function (ContainerInterface $c): OpportunisticSchedulerMiddleware {
        // Default 10% Wahrscheinlichkeit pro Dashboard-Aufruf, max 5 Jobs pro Trigger.
        // Werte koennen spaeter ins settings-System wandern, wenn Bedarf besteht.
        return new OpportunisticSchedulerMiddleware(
            $c->get(SchedulerService::class),
            $c->get(LoggerInterface::class),
            10,
            5
        );
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
            $c->get(EventRepository::class),
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
            $c->get('settings'),
            $c->get(EntryLockService::class)
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
            $c->get('settings'),
            $c->get(LoggerInterface::class)
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
            $c->get(EventTemplateRepository::class),
            $c->get('settings'),
            $c->get(SchedulerService::class),
            $c->get(\App\Services\TaskTreeService::class),
            $c->get(\App\Services\TaskTreeAggregator::class),
            $c->get(EventTaskAssignmentRepository::class),
            $c->get(SettingsService::class)
        );
    },

    EventTemplateController::class => function (ContainerInterface $c): EventTemplateController {
        return new EventTemplateController(
            $c->get(EventTemplateRepository::class),
            $c->get(CategoryRepository::class),
            $c->get(AuditService::class),
            $c->get(\App\Services\EventTemplateService::class),
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
            $c->get(\App\Services\CalendarFeedService::class),
            $c->get(UserRepository::class),
            $c->get(AuditService::class),
            $c->get('settings'),
            $c->get(\App\Services\TaskTreeAggregator::class),
            $c->get(SettingsService::class)
        );
    },

    // --- Modul 6 I5: iCal-Controller ----------------------------------------
    IcalController::class => function (ContainerInterface $c): IcalController {
        return new IcalController(
            $c->get(EventRepository::class),
            $c->get(UserRepository::class),
            $c->get(\App\Services\IcalService::class)
        );
    },

    // --- Modul 6 I6: Cron-Controller (externer Pinger-Endpunkt) -------------
    CronController::class => function (ContainerInterface $c): CronController {
        return new CronController(
            $c->get(SchedulerService::class),
            $c->get(SettingsRepository::class),
            $c->get(RateLimitService::class),
            $c->get(LoggerInterface::class)
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
