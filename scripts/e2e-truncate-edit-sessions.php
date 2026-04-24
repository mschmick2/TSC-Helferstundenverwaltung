<?php

declare(strict_types=1);

/**
 * e2e-truncate-edit-sessions.php
 *
 * Leert die `edit_sessions`-Tabelle in der E2E-DB. Wird aus
 * `tests/e2e/specs/17-edit-sessions.spec.ts` im beforeEach
 * aufgerufen, damit Sessions aus dem vorigen Test nicht durch
 * die Multi-Tab-Deduplikation in EditSessionView::toJsonReadyArray
 * den naechsten Test verfaelschen.
 *
 * Hintergrund: Server-Filter haelt Sessions bis ACTIVE_TIMEOUT_SECONDS
 * (120 s) als aktiv. Mehrere Tests in serial Mode laufen schneller
 * als der Timeout, also bleibt das DB-Residuum sichtbar -- ohne
 * expliziten Cleanup wuerde z.B. Test "A schliesst Seite" nie sehen,
 * dass A's Session weg ist, weil A's Vor-Test-Session noch im DB-
 * Active-Window haengt.
 *
 * Verwendung:  php scripts/e2e-truncate-edit-sessions.php
 * Environment: wie scripts/setup-e2e-db.php.
 */

$host   = getenv('DB_HOST')         ?: '127.0.0.1';
$port   = (int) (getenv('DB_PORT')  ?: 3306);
$user   = getenv('DB_USERNAME')     ?: 'root';
$pass   = getenv('DB_PASSWORD')     ?: '';
$target = getenv('DB_E2E_DATABASE') ?: 'helferstunden_e2e';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$target;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );
    $pdo->exec('TRUNCATE TABLE edit_sessions');
    echo "edit_sessions geleert.\n";
} catch (\PDOException $e) {
    fwrite(STDERR, "Fehler beim Leeren von edit_sessions: " . $e->getMessage() . "\n");
    exit(1);
}
