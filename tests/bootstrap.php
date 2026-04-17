<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap fuer VAES-Tests.
 *
 * - Laedt Composer-Autoloader
 * - Stellt session-Superglobale bereit
 * - Definiert APP_ROOT/TEST_ROOT Konstanten
 * - Faehrt eine In-Memory-Session fuer Tests ohne echten Client hoch
 */

define('TEST_ROOT', __DIR__);
define('APP_ROOT', dirname(__DIR__) . '/src');

require_once APP_ROOT . '/vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_cookies', '0');
    ini_set('session.use_only_cookies', '0');
    ini_set('session.cache_limiter', '');
}

if (!isset($_SESSION)) {
    $_SESSION = [];
}

// Fallback: $_SERVER-Felder, die Slim/Controller erwarten
$_SERVER['REMOTE_ADDR']     = $_SERVER['REMOTE_ADDR']     ?? '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'PHPUnit';
$_SERVER['HTTP_HOST']       = $_SERVER['HTTP_HOST']       ?? 'localhost';
$_SERVER['REQUEST_METHOD']  = $_SERVER['REQUEST_METHOD']  ?? 'GET';
$_SERVER['REQUEST_URI']     = $_SERVER['REQUEST_URI']     ?? '/';
