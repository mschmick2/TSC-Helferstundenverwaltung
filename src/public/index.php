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
$app->addErrorMiddleware($displayErrors, true, true);

// Security-Header-Middleware: als letztes hinzufuegen -> laeuft als ERSTES und
// setzt CSP/HSTS/Permissions-Policy auf JEDE Response, auch auf Error- und
// Redirect-Responses. Entspricht CLAUDE.md §8 Nr. 3.
$app->add(new \App\Middleware\SecurityHeadersMiddleware());

// Routen laden
(require $appRoot . '/config/routes.php')($app);

// App ausfuehren
$app->run();
