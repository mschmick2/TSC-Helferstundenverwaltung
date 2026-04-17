# 🧩 Rules: Framework (Slim 4 / PHP-DI — VAES)

Geladen von: `coder.md` (G2), bei Bedarf `architect.md` (G1).

---

## Slim 4 — Routing

**DO (zentrale Route-Registrierung in `src/public/index.php` oder dediziertem Router):**
```php
$app->get('/entries', [WorkEntryController::class, 'index'])
    ->add(new AuthMiddleware($container));

$app->post('/entries', [WorkEntryController::class, 'store'])
    ->add(new AuthMiddleware($container))
    ->add(new CsrfMiddleware($container));

$app->post('/entries/{id}/approve', [WorkEntryController::class, 'approve'])
    ->add(new AuthMiddleware($container))
    ->add(new RoleMiddleware(['pruefer', 'administrator']))
    ->add(new CsrfMiddleware($container));
```

**Middleware-Reihenfolge (Ausfuehrung von aussen nach innen):**
1. Session-Start
2. CSRF
3. Auth
4. Role
5. Controller

Slim fuehrt Middleware in umgekehrter Reihenfolge der `add()`-Aufrufe aus, also **zuerst** hinzugefuegte Middleware laeuft **zuletzt**. Immer pruefen!

---

## Controller — Basis

**DO:**
```php
<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\WorkflowService;
use App\Exceptions\BusinessRuleException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class WorkEntryController extends BaseController
{
    public function __construct(
        private readonly WorkflowService $workflow,
    ) {}

    public function approve(Request $request, Response $response, array $args): Response
    {
        $entryId = (int) $args['id'];
        $userId = $this->currentUserId($request);

        try {
            $this->workflow->approve($entryId, reviewerId: $userId);
            $this->flash($request, 'success', 'Antrag freigegeben.');
        } catch (BusinessRuleException $e) {
            $this->flash($request, 'danger', $e->getMessage());
        }

        return $this->redirect($response, '/entries/' . $entryId);
    }
}
```

---

## Dependency Injection (PHP-DI)

**DO (Container-Config in `src/config/container.php` oder aehnlich):**
```php
use PDO;
use function DI\autowire;
use function DI\factory;

return [
    PDO::class => factory(function () {
        $config = require __DIR__ . '/config.php';
        $pdo = new PDO(
            "mysql:host={$config['db']['host']};dbname={$config['db']['name']};charset=utf8mb4",
            $config['db']['user'],
            $config['db']['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        return $pdo;
    }),

    // Autowiring fuer Services/Repos/Controllers
    WorkflowService::class => autowire(),
    WorkEntryRepository::class => autowire(),
];
```

---

## Views (PHP-Templates)

**DO:**
```php
<?php
// src/app/Views/entries/show.php
/** @var App\Models\WorkEntry $entry */
/** @var array $csrfToken */
?>
<?php require __DIR__ . '/../layouts/main_header.php'; ?>

<h1><?= ViewHelper::e($entry->title) ?></h1>

<?php if ($entry->canApprove($currentUser)): ?>
    <form method="POST" action="/entries/<?= (int)$entry->id ?>/approve">
        <input type="hidden" name="csrf_token" value="<?= ViewHelper::e($csrfToken) ?>">
        <button type="submit" class="btn btn-success">Freigeben</button>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/../layouts/main_footer.php'; ?>
```

**DON'T:**
- Kein PHP-Framework-Templating (Twig/Smarty) — reine PHP-Templates Pflicht
- Kein DB-Zugriff aus Views
- Keine Business-Logik in Views (nur Render-Bedingungen)

---

## Exceptions

**Benutzerdefinierte Hierarchie in `src/app/Exceptions/`:**
- `AuthenticationException` — Login fehlgeschlagen
- `AuthorizationException` — fehlende Rechte
- `BusinessRuleException` — Geschaeftsregel verletzt
- `ValidationException` — Eingabe ungueltig

**Globaler Error-Handler in `src/public/index.php`:**
```php
$errorMiddleware = $app->addErrorMiddleware(
    displayErrorDetails: $config['debug'] ?? false,
    logErrors: true,
    logErrorDetails: true
);

$errorMiddleware->setErrorHandler(
    AuthorizationException::class,
    function (Request $request, Throwable $exception) use ($app) {
        $response = $app->getResponseFactory()->createResponse();
        // Redirect zu Login oder 403-Page
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
);
```

---

## Request/Response

**Body parsen:**
```php
$parsedBody = (array) $request->getParsedBody();
$id = (int) ($parsedBody['id'] ?? 0);
```

**JSON-Response:**
```php
$response = $response
    ->withHeader('Content-Type', 'application/json')
    ->withStatus(200);
$response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
return $response;
```

**Redirect:**
```php
return $response
    ->withHeader('Location', '/entries/' . $id)
    ->withStatus(303);
```

---

## PSR-4 Autoload

`composer.json`:
```json
{
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    }
}
```

Bei neuen Dateien:
```bash
cd src && composer dump-autoload
```

---

## Konfiguration

`src/config/config.php` (Vorlage: `config.example.php`):
```php
return [
    'debug' => false,
    'base_path' => '',
    'db' => [
        'host' => 'localhost',
        'name' => 'vaes',
        'user' => '...',
        'pass' => '...',
    ],
    'mail' => [
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 587,
        'smtp_user' => '...',
        'smtp_pass' => '...',
        'from_address' => 'vaes@example.com',
        'from_name' => 'VAES',
    ],
    'session' => [
        'timeout_minutes' => 30,
    ],
    'invitation' => [
        'token_ttl_days' => 7,
    ],
];
```

---

## Verbotenes

- Neue Frameworks/Micro-Frameworks einfuehren (Slim 4 bleibt)
- `$_POST`/`$_GET` direkt in Services/Repositories
- Singleton-Patterns (DI-Container macht das)
- Globale Variablen
- `require`/`include` von Code-Dateien ausserhalb View-Templates
- Autoloader umgehen mit manuellen `require` fuer Klassen
