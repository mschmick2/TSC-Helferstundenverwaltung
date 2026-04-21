<?php

declare(strict_types=1);

/**
 * e2e-truncate-rate-limits.php
 *
 * Leert die `rate_limits`-Tabelle in der E2E-DB. Wird von der E2E-Spec
 * `09-password-reset-rate-limit.spec.ts` im beforeEach aufgerufen, damit
 * aufeinanderfolgende Tests nicht gegen denselben ::1-IP-Bucket laufen
 * (alle Requests kommen vom lokalen PHP-Dev-Server).
 *
 * Verwendung:  php scripts/e2e-truncate-rate-limits.php
 * Environment: wie scripts/setup-e2e-db.php (DB_HOST, DB_PORT, DB_USERNAME,
 *              DB_PASSWORD, DB_E2E_DATABASE).
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
    $pdo->exec('TRUNCATE TABLE rate_limits');
    echo "rate_limits geleert.\n";
} catch (\PDOException $e) {
    fwrite(STDERR, "Fehler beim Leeren von rate_limits: " . $e->getMessage() . "\n");
    exit(1);
}
