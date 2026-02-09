# ARCHITECTURE.md - VAES Technische Architektur

**Version:** 1.3  
**Stand:** 2025-02-09

---

## Architektur-Übersicht

```
┌─────────────────────────────────────────────────────────────────┐
│                        Browser (Client)                          │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐              │
│  │   Desktop   │  │   Tablet    │  │ Smartphone  │              │
│  └─────────────┘  └─────────────┘  └─────────────┘              │
└────────────────────────────┬────────────────────────────────────┘
                             │ HTTPS
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Strato Webhosting                             │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │                     Apache Webserver                       │  │
│  │  ┌─────────────────────────────────────────────────────┐  │  │
│  │  │                    .htaccess                         │  │  │
│  │  │  • URL Rewriting → index.php                        │  │  │
│  │  │  • Security Headers                                  │  │  │
│  │  │  • Verzeichnisschutz                                │  │  │
│  │  └─────────────────────────────────────────────────────┘  │  │
│  └───────────────────────────────────────────────────────────┘  │
│                             │                                    │
│                             ▼                                    │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │                     PHP 8.x + Slim 4                       │  │
│  │  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐  │  │
│  │  │Controllers│  │ Services │  │  Models  │  │  Views   │  │  │
│  │  └──────────┘  └──────────┘  └──────────┘  └──────────┘  │  │
│  │         │             │             │             │        │  │
│  │         └─────────────┼─────────────┘             │        │  │
│  │                       │                           │        │  │
│  │                       ▼                           │        │  │
│  │  ┌─────────────────────────────────────────────────────┐  │  │
│  │  │              PDO (Prepared Statements)               │  │  │
│  │  └─────────────────────────────────────────────────────┘  │  │
│  └───────────────────────────────────────────────────────────┘  │
│                             │                                    │
│                             ▼                                    │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │                      MySQL 8.4                             │  │
│  │  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐         │  │
│  │  │  users  │ │ entries │ │ dialogs │ │  audit  │  ...    │  │
│  │  └─────────┘ └─────────┘ └─────────┘ └─────────┘         │  │
│  └───────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Verzeichnisstruktur

```
src/
├── public/                     # Web-Root (öffentlich erreichbar)
│   ├── index.php              # Front Controller
│   ├── .htaccess              # URL Rewriting, Security
│   ├── css/
│   │   ├── app.css            # Eigene Styles
│   │   └── bootstrap.min.css  # Bootstrap 5
│   ├── js/
│   │   ├── app.js             # Eigene Scripts
│   │   └── bootstrap.bundle.min.js
│   └── assets/
│       ├── images/
│       └── fonts/
│
├── app/                        # Anwendungslogik (geschützt!)
│   ├── .htaccess              # Zugriff verbieten
│   │
│   ├── Controllers/           # Request Handler
│   │   ├── AuthController.php
│   │   ├── DashboardController.php
│   │   ├── WorkEntryController.php
│   │   ├── CategoryController.php
│   │   ├── UserController.php
│   │   ├── ReportController.php
│   │   └── AdminController.php
│   │
│   ├── Models/                # Datenmodelle
│   │   ├── User.php
│   │   ├── Role.php
│   │   ├── WorkEntry.php
│   │   ├── Category.php
│   │   ├── DialogMessage.php
│   │   └── AuditLog.php
│   │
│   ├── Services/              # Business Logic
│   │   ├── AuthService.php
│   │   ├── TotpService.php
│   │   ├── WorkflowService.php
│   │   ├── AuditService.php
│   │   ├── EmailService.php
│   │   ├── ImportService.php
│   │   ├── ReportService.php
│   │   └── PdfService.php
│   │
│   ├── Repositories/          # Datenzugriff
│   │   ├── UserRepository.php
│   │   ├── WorkEntryRepository.php
│   │   ├── CategoryRepository.php
│   │   └── AuditRepository.php
│   │
│   ├── Middleware/            # Request/Response Filter
│   │   ├── AuthMiddleware.php
│   │   ├── CsrfMiddleware.php
│   │   ├── RoleMiddleware.php
│   │   └── AuditMiddleware.php
│   │
│   ├── Exceptions/            # Custom Exceptions
│   │   ├── AuthenticationException.php
│   │   ├── AuthorizationException.php
│   │   ├── BusinessRuleException.php
│   │   └── ValidationException.php
│   │
│   ├── Helpers/               # Utility Functions
│   │   ├── ViewHelper.php
│   │   ├── DateHelper.php
│   │   └── SecurityHelper.php
│   │
│   └── Views/                 # Templates
│       ├── layouts/
│       │   ├── main.php       # Haupt-Layout
│       │   └── auth.php       # Login-Layout
│       ├── auth/
│       │   ├── login.php
│       │   ├── 2fa.php
│       │   ├── setup-password.php
│       │   └── reset-password.php
│       ├── dashboard/
│       │   └── index.php
│       ├── entries/
│       │   ├── index.php
│       │   ├── create.php
│       │   ├── edit.php
│       │   ├── show.php
│       │   └── _dialog.php
│       ├── admin/
│       │   ├── users/
│       │   ├── categories/
│       │   └── settings/
│       ├── reports/
│       └── components/
│           ├── _navbar.php
│           ├── _sidebar.php
│           ├── _flash.php
│           └── _pagination.php
│
├── config/                    # Konfiguration (geschützt!)
│   ├── .htaccess             # Zugriff verbieten
│   ├── config.php            # Hauptkonfiguration (NICHT in Git!)
│   ├── config.example.php    # Beispielkonfiguration
│   └── routes.php            # Routen-Definition
│
├── vendor/                    # Composer Dependencies (geschützt!)
│   └── .htaccess             # Zugriff verbieten
│
└── storage/                   # Logs, Cache (geschützt!)
    ├── .htaccess             # Zugriff verbieten
    ├── logs/
    │   └── app.log
    └── cache/
```

---

## Komponenten-Design

### Front Controller (index.php)

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;

// Container erstellen
$container = new Container();
AppFactory::setContainer($container);

// Dependencies registrieren
require __DIR__ . '/../config/dependencies.php';

// App erstellen
$app = AppFactory::create();

// Middleware registrieren
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(
    displayErrorDetails: $container->get('settings')['debug'],
    logErrors: true,
    logErrorDetails: true
);

// Routen laden
require __DIR__ . '/../config/routes.php';

// App ausführen
$app->run();
```

### Routing (routes.php)

```php
<?php
use Slim\Routing\RouteCollectorProxy;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

// Öffentliche Routen
$app->group('', function (RouteCollectorProxy $group) {
    $group->get('/login', [AuthController::class, 'showLogin']);
    $group->post('/login', [AuthController::class, 'login']);
    $group->get('/2fa', [AuthController::class, 'show2fa']);
    $group->post('/2fa', [AuthController::class, 'verify2fa']);
    $group->get('/setup-password/{token}', [AuthController::class, 'showSetupPassword']);
    $group->post('/setup-password/{token}', [AuthController::class, 'setupPassword']);
});

// Geschützte Routen (Login erforderlich)
$app->group('', function (RouteCollectorProxy $group) {
    
    $group->get('/', [DashboardController::class, 'index']);
    $group->get('/logout', [AuthController::class, 'logout']);
    
    // Arbeitsstunden
    $group->get('/entries', [WorkEntryController::class, 'index']);
    $group->get('/entries/create', [WorkEntryController::class, 'create']);
    $group->post('/entries', [WorkEntryController::class, 'store']);
    $group->get('/entries/{id}', [WorkEntryController::class, 'show']);
    $group->get('/entries/{id}/edit', [WorkEntryController::class, 'edit']);
    $group->put('/entries/{id}', [WorkEntryController::class, 'update']);
    $group->delete('/entries/{id}', [WorkEntryController::class, 'delete']);
    
    // Workflow-Aktionen
    $group->post('/entries/{id}/submit', [WorkEntryController::class, 'submit']);
    $group->post('/entries/{id}/withdraw', [WorkEntryController::class, 'withdraw']);
    $group->post('/entries/{id}/reactivate', [WorkEntryController::class, 'reactivate']);
    
    // Dialog
    $group->post('/entries/{id}/dialog', [WorkEntryController::class, 'addDialogMessage']);
    
    // Reports
    $group->get('/reports', [ReportController::class, 'index']);
    $group->get('/reports/export/{type}', [ReportController::class, 'export']);
    
})->add(AuthMiddleware::class);

// Prüfer-Routen
$app->group('/review', function (RouteCollectorProxy $group) {
    $group->get('', [WorkEntryController::class, 'reviewList']);
    $group->post('/{id}/approve', [WorkEntryController::class, 'approve']);
    $group->post('/{id}/reject', [WorkEntryController::class, 'reject']);
    $group->post('/{id}/return', [WorkEntryController::class, 'returnForRevision']);
    $group->post('/{id}/correct', [WorkEntryController::class, 'correct']);
})->add(RoleMiddleware::class)->add(AuthMiddleware::class);

// Admin-Routen
$app->group('/admin', function (RouteCollectorProxy $group) {
    // Benutzer
    $group->get('/users', [UserController::class, 'index']);
    $group->post('/users/import', [UserController::class, 'import']);
    $group->post('/users/{id}/reinvite', [UserController::class, 'reinvite']);
    
    // Kategorien
    $group->get('/categories', [CategoryController::class, 'index']);
    $group->post('/categories', [CategoryController::class, 'store']);
    $group->put('/categories/{id}', [CategoryController::class, 'update']);
    $group->delete('/categories/{id}', [CategoryController::class, 'delete']);
    
    // Einstellungen
    $group->get('/settings', [AdminController::class, 'settings']);
    $group->post('/settings', [AdminController::class, 'updateSettings']);
    
})->add(new RoleMiddleware(['administrator']))->add(AuthMiddleware::class);
```

---

## Service-Schicht

### WorkflowService

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\WorkEntry;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidStatusTransitionException;

class WorkflowService
{
    private const TRANSITIONS = [
        'entwurf' => ['eingereicht'],
        'eingereicht' => ['in_klaerung', 'freigegeben', 'abgelehnt', 'entwurf', 'storniert'],
        'in_klaerung' => ['freigegeben', 'abgelehnt', 'entwurf', 'storniert'],
        'storniert' => ['entwurf'],
        'freigegeben' => [],
        'abgelehnt' => [],
    ];
    
    public function __construct(
        private WorkEntryRepository $repository,
        private AuditService $auditService,
        private EmailService $emailService
    ) {}
    
    public function submit(WorkEntry $entry, int $userId): void
    {
        $this->validateTransition($entry, 'eingereicht');
        
        $entry->setStatus('eingereicht');
        $entry->setSubmittedAt(new \DateTime());
        
        $this->repository->save($entry);
        $this->auditService->logStatusChange($entry, 'entwurf', 'eingereicht', $userId);
        $this->emailService->notifyReviewersNewEntry($entry);
    }
    
    public function approve(WorkEntry $entry, int $reviewerId, ?string $note = null): void
    {
        // KRITISCH: Selbstgenehmigung verhindern!
        if ($entry->getUserId() === $reviewerId) {
            throw new BusinessRuleException(
                'Eigene Anträge können nicht selbst genehmigt werden.'
            );
        }
        
        $this->validateTransition($entry, 'freigegeben');
        
        $oldStatus = $entry->getStatus();
        $entry->setStatus('freigegeben');
        $entry->setReviewedByUserId($reviewerId);
        $entry->setReviewedAt(new \DateTime());
        
        $this->repository->save($entry);
        $this->auditService->logStatusChange($entry, $oldStatus, 'freigegeben', $reviewerId, $note);
        $this->emailService->notifyMemberApproval($entry);
    }
    
    public function reject(WorkEntry $entry, int $reviewerId, string $reason): void
    {
        // Selbstgenehmigung verhindern
        if ($entry->getUserId() === $reviewerId) {
            throw new BusinessRuleException(
                'Eigene Anträge können nicht selbst abgelehnt werden.'
            );
        }
        
        $this->validateTransition($entry, 'abgelehnt');
        
        $oldStatus = $entry->getStatus();
        $entry->setStatus('abgelehnt');
        $entry->setRejectionReason($reason);
        $entry->setReviewedByUserId($reviewerId);
        $entry->setReviewedAt(new \DateTime());
        
        $this->repository->save($entry);
        $this->auditService->logStatusChange($entry, $oldStatus, 'abgelehnt', $reviewerId, $reason);
        $this->emailService->notifyMemberRejection($entry, $reason);
    }
    
    public function returnForRevision(WorkEntry $entry, int $reviewerId, string $reason): void
    {
        // Selbstaktion verhindern
        if ($entry->getUserId() === $reviewerId) {
            throw new BusinessRuleException(
                'Eigene Anträge können nicht zur Überarbeitung zurückgegeben werden.'
            );
        }
        
        $this->validateTransition($entry, 'entwurf');
        
        $oldStatus = $entry->getStatus();
        $entry->setStatus('entwurf');
        $entry->setReturnReason($reason);
        
        $this->repository->save($entry);
        $this->auditService->logStatusChange($entry, $oldStatus, 'entwurf', $reviewerId, $reason);
        $this->emailService->notifyMemberReturnForRevision($entry, $reason);
    }
    
    private function validateTransition(WorkEntry $entry, string $newStatus): void
    {
        $currentStatus = $entry->getStatus();
        $allowed = self::TRANSITIONS[$currentStatus] ?? [];
        
        if (!in_array($newStatus, $allowed)) {
            throw new InvalidStatusTransitionException(
                "Übergang von '$currentStatus' nach '$newStatus' ist nicht erlaubt."
            );
        }
    }
}
```

### AuditService

```php
<?php
declare(strict_types=1);

namespace App\Services;

class AuditService
{
    public function __construct(
        private PDO $pdo,
        private ?int $currentUserId = null,
        private ?int $sessionId = null,
        private ?string $ipAddress = null
    ) {}
    
    public function log(
        string $action,
        ?string $tableName = null,
        ?int $recordId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?string $entryNumber = null
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO audit_log 
            (user_id, session_id, ip_address, action, table_name, record_id, 
             entry_number, old_values, new_values, description, created_at)
            VALUES 
            (:user_id, :session_id, :ip_address, :action, :table_name, :record_id,
             :entry_number, :old_values, :new_values, :description, NOW())
        ");
        
        $stmt->execute([
            'user_id' => $this->currentUserId,
            'session_id' => $this->sessionId,
            'ip_address' => $this->ipAddress,
            'action' => $action,
            'table_name' => $tableName,
            'record_id' => $recordId,
            'entry_number' => $entryNumber,
            'old_values' => $oldValues ? json_encode($oldValues) : null,
            'new_values' => $newValues ? json_encode($newValues) : null,
            'description' => $description,
        ]);
    }
    
    public function logStatusChange(
        WorkEntry $entry,
        string $oldStatus,
        string $newStatus,
        int $userId,
        ?string $reason = null
    ): void {
        $this->log(
            action: 'status_change',
            tableName: 'work_entries',
            recordId: $entry->getId(),
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => $newStatus],
            description: $reason,
            entryNumber: $entry->getEntryNumber()
        );
    }
}
```

---

## Middleware

### AuthMiddleware

```php
<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Response as SlimResponse;

class AuthMiddleware
{
    public function __construct(
        private AuthService $authService
    ) {}
    
    public function __invoke(Request $request, Handler $handler): Response
    {
        if (!$this->authService->isAuthenticated()) {
            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }
        
        // Session-Timeout prüfen
        if ($this->authService->isSessionExpired()) {
            $this->authService->logout();
            $response = new SlimResponse();
            return $response
                ->withHeader('Location', '/login?expired=1')
                ->withStatus(302);
        }
        
        // Session verlängern
        $this->authService->refreshSession();
        
        return $handler->handle($request);
    }
}
```

### RoleMiddleware

```php
<?php
declare(strict_types=1);

namespace App\Middleware;

class RoleMiddleware
{
    public function __construct(
        private array $requiredRoles = ['pruefer']
    ) {}
    
    public function __invoke(Request $request, Handler $handler): Response
    {
        $user = $request->getAttribute('user');
        
        $hasRole = false;
        foreach ($this->requiredRoles as $role) {
            if ($user->hasRole($role)) {
                $hasRole = true;
                break;
            }
        }
        
        if (!$hasRole) {
            throw new AuthorizationException(
                'Sie haben keine Berechtigung für diese Aktion.'
            );
        }
        
        return $handler->handle($request);
    }
}
```

---

## Sicherheitsarchitektur

### CSRF-Schutz

```php
// Token generieren (einmal pro Session)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// In Formularen
<input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

// Validierung
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    throw new SecurityException('Ungültiges CSRF-Token');
}
```

### Prepared Statements (IMMER!)

```php
// IMMER so:
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND email = :email");
$stmt->execute(['id' => $id, 'email' => $email]);

// NIE so:
$pdo->query("SELECT * FROM users WHERE id = $id"); // GEFÄHRLICH!
```

### Output Escaping

```php
// In Views IMMER escapen:
<?= htmlspecialchars($user->getName(), ENT_QUOTES, 'UTF-8') ?>

// Oder Helper verwenden:
<?= e($user->getName()) ?>
```

---

## Deployment-Architektur

```
Entwicklung (lokal)                    Produktion (Strato)
────────────────────                   ─────────────────────
E:\TSC-Helferstundenverwaltung\       /htdocs/
├── src/                               ├── index.php
│   ├── public/  ─────────────────────►├── css/
│   ├── app/     ─────────────────────►├── js/
│   ├── config/  ─────────────────────►├── app/
│   ├── vendor/  ─────────────────────►├── config/
│   └── storage/ ─────────────────────►├── vendor/
│                                      └── storage/
│
├── docs/        (nicht deployen)
├── tests/       (nicht deployen)
└── scripts/     (nicht deployen)
```

---

*Letzte Aktualisierung: 2025-02-09*
