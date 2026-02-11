<?php

declare(strict_types=1);

/**
 * PHPUnit Bootstrap für VAES-Tests
 *
 * Lädt den Composer-Autoloader und initialisiert die Testumgebung.
 */

// Composer Autoloader
require_once __DIR__ . '/../src/vendor/autoload.php';

// Session-Simulation für Tests (ohne tatsächliche Session-Initialisierung)
if (session_status() === PHP_SESSION_NONE) {
    // Verwende einen Memory-Handler, damit Tests keine echte Session brauchen
    ini_set('session.use_cookies', '0');
    ini_set('session.use_only_cookies', '0');
    ini_set('session.cache_limiter', '');
}

// Superglobale $_SESSION bereitstellen (falls nicht vorhanden)
if (!isset($_SESSION)) {
    $_SESSION = [];
}
