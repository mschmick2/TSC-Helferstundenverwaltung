<?php
/**
 * Router-Script fuer den PHP Built-in Dev-Server.
 *
 * Loest das Problem, dass Static-Assets unter einem base_path-Prefix
 * (z.B. /helferstunden/js/app.js) vom PHP-Dev-Server nicht gefunden werden,
 * weil er sie physikalisch unter src/public/helferstunden/js/app.js sucht.
 *
 * Start:
 *   cd src/public
 *   php -S localhost:8000 router.php
 *
 * Das Router-Script strippt den base_path, prueft ob eine physische Datei
 * existiert, und liefert sie aus. Fuer alle uebrigen Requests laesst es
 * index.php uebernehmen.
 *
 * WICHTIG: Ausschliesslich fuer lokale Entwicklung. Auf Strato uebernimmt
 * .htaccess die Rewrites.
 */

declare(strict_types=1);

// Base-Path aus config.php lesen (Single Source of Truth)
$config = @file_exists(__DIR__ . '/../config/config.php')
    ? require __DIR__ . '/../config/config.php'
    : [];
$basePath = $config['app']['base_path'] ?? '';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

// Base-Path strippen, falls vorhanden
if ($basePath !== '' && str_starts_with($uri, $basePath)) {
    $uri = substr($uri, strlen($basePath));
    if ($uri === '' || $uri === false) {
        $uri = '/';
    }
}

// Physikalische Datei unter public/ suchen (z.B. css/, js/, img/)
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file) && is_file($file)) {
    return false; // PHP-Dev-Server liefert die Datei selbst aus
}

// Andernfalls: index.php uebernehmen (Slim-Routing)
require __DIR__ . '/index.php';
