<?php

declare(strict_types=1);

/**
 * VAES - Front Controller
 *
 * Alle Requests werden ueber .htaccess hierher geleitet.
 */

// Zeitzone setzen
date_default_timezone_set('Europe/Berlin');

// Auto-detect: public/-Unterverzeichnis (Entwicklung) vs. Flat (Deployment)
// Entwicklung: index.php liegt in src/public/, vendor/ liegt in src/vendor/ => dirname(__DIR__)
// Deployment:  index.php liegt neben vendor/ im gleichen Verzeichnis => __DIR__
$appRoot = is_dir(__DIR__ . '/vendor') ? __DIR__ : dirname(__DIR__);

// Autoloader laden
require $appRoot . '/vendor/autoload.php';

// Config-Datei: standardmaessig config.php, via VAES_CONFIG_FILE umschaltbar (Modul 8 E2E).
$configFile = getenv('VAES_CONFIG_FILE') ?: $appRoot . '/config/config.php';

// Session konfigurieren und starten (zentral, einmalig)
if (session_status() === PHP_SESSION_NONE) {
    $sessionConfig = require $configFile;
    $sessionSettings = $sessionConfig['session'] ?? [];
    $sessionBasePath = $sessionConfig['app']['base_path'] ?? '';
    session_name($sessionSettings['name'] ?? 'VAES_SESSION');

    // HTTPS auto-detect: config-Wert respektieren, aber bei HTTP erzwungen false
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $sessionSecure = ($sessionSettings['secure'] ?? true) && $isHttps;

    session_set_cookie_params([
        'lifetime' => $sessionSettings['lifetime'] ?? 1800,
        'path' => $sessionBasePath !== '' ? $sessionBasePath . '/' : ($sessionSettings['path'] ?? '/'),
        'domain' => $sessionSettings['domain'] ?? '',
        'secure' => $sessionSecure,
        'httponly' => $sessionSettings['httponly'] ?? true,
        'samesite' => $sessionSettings['samesite'] ?? 'Lax',
    ]);
    session_start();
}

use DI\Bridge\Slim\Bridge as SlimBridge;
use DI\ContainerBuilder;

// Container erstellen
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions($appRoot . '/config/dependencies.php');
$container = $containerBuilder->build();

// Slim App mit PHP-DI erstellen
$app = SlimBridge::create($container);

// Basis-Pfad setzen (fuer Unterverzeichnis-Installationen)
$basePath = $container->get('settings')['app']['base_path'] ?? '';
if ($basePath) {
    $app->setBasePath($basePath);
}

// ViewHelper den Basis-Pfad mitteilen
\App\Helpers\ViewHelper::setBasePath($basePath);

// Middleware registrieren (Reihenfolge: zuletzt hinzugefuegt wird zuerst ausgefuehrt)
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

// Error Middleware
$displayErrors = $container->get('settings')['app']['debug'] ?? false;
$errorMiddleware = $app->addErrorMiddleware($displayErrors, true, true);

// Modul 6 I8 Phase 1 (Follow-up v): AuthorizationException-Handler. Jede
// unauthorisierte Controller-/Service-Aktion, die diese Exception wirft
// (z.B. BaseController::assertEventEditPermission, WorkflowService,
// EventAssignmentService), schreibt einen audit_log-Eintrag mit
// action='access_denied'. Der Handler gibt danach die passende Response
// zurueck -- HTML-Clients bekommen Redirect mit Flash, JSON-Clients
// (Accept: application/json) bekommen eine strukturierte 403-Response.
$errorMiddleware->setErrorHandler(
    \App\Exceptions\AuthorizationException::class,
    function (
        \Psr\Http\Message\ServerRequestInterface $request,
        \Throwable $exception
    ) use ($container) {
        /** @var \App\Services\AuditService $auditService */
        $auditService = $container->get(\App\Services\AuditService::class);
        /** @var \App\Exceptions\AuthorizationException $exception */
        $auditService->logAccessDenied(
            route: $request->getUri()->getPath(),
            method: $request->getMethod(),
            reason: $exception->getReason(),
            metadata: $exception->getMetadata()
        );

        $wantsJson = str_contains(
            $request->getHeaderLine('Accept'),
            'application/json'
        );

        $response = new \Slim\Psr7\Response();

        if ($wantsJson) {
            $response->getBody()->write(json_encode(
                [
                    'error'   => 'access_denied',
                    'reason'  => $exception->getReason(),
                    'message' => $exception->getMessage()
                        ?: 'Sie haben keine Berechtigung fuer diese Aktion.',
                ],
                JSON_UNESCAPED_UNICODE
            ));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(403);
        }

        // HTML-Client: Flash + Redirect zur Startseite (wie bisher in
        // RoleMiddleware). Das bleibt der Bestand-UX treu -- der Nutzer
        // sieht eine freundliche Meldung und landet auf einer Seite,
        // die er bedienen darf.
        $message = $exception->getMessage()
            ?: 'Sie haben keine Berechtigung fuer diese Aktion.';
        \App\Helpers\ViewHelper::flash('danger', $message);
        $basePath = \App\Helpers\ViewHelper::getBasePath();
        return $response
            ->withHeader('Location', $basePath . '/')
            ->withStatus(302);
    }
);

// Modul 6 I8 Phase 1: RoleMiddleware wird in routes.php per
// `new RoleMiddleware([...])` mit Rollen-Parametern instanziiert, daher
// nicht ueber den Container. Der AuditService wird einmalig im Bootstrap
// statisch gesetzt (Details im RoleMiddleware-Docblock).
\App\Middleware\RoleMiddleware::setAuditService(
    $container->get(\App\Services\AuditService::class)
);

// Security-Header-Middleware: als letztes hinzufuegen -> laeuft als ERSTES und
// setzt CSP/HSTS/Permissions-Policy auf JEDE Response, auch auf Error- und
// Redirect-Responses. Entspricht CLAUDE.md §8 Nr. 3.
$app->add(new \App\Middleware\SecurityHeadersMiddleware());

// Routen laden
(require $appRoot . '/config/routes.php')($app);

// App ausfuehren
$app->run();
