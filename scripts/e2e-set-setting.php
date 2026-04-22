<?php

declare(strict_types=1);

/**
 * e2e-set-setting.php
 *
 * Setzt einen Settings-Eintrag in der E2E-DB per Kommandozeile. Dient der
 * Playwright-Suite, um Feature-Flags (z.B. events.tree_editor_enabled) zwischen
 * Specs umzuschalten, ohne die gesamte DB neu aufzubauen.
 *
 * Verwendung:
 *   php scripts/e2e-set-setting.php <key> <value>
 *
 * Beispiel:
 *   php scripts/e2e-set-setting.php events.tree_editor_enabled 1
 *
 * Environment (optional, Defaults zielen auf helferstunden_e2e):
 *   DB_HOST             127.0.0.1
 *   DB_PORT             3306
 *   DB_USERNAME         root
 *   DB_PASSWORD         ""
 *   DB_E2E_DATABASE     helferstunden_e2e
 *
 * Rueckgabe:
 *   exit 0 bei Erfolg, exit 1 bei Fehler (Message auf STDERR).
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/e2e-set-setting.php <key> <value>\n");
    exit(1);
}

$key   = (string) $argv[1];
$value = (string) $argv[2];

if ($key === '') {
    fwrite(STDERR, "FEHLER: leerer key.\n");
    exit(1);
}

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
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "FEHLER: MySQL-Verbindung fehlgeschlagen: " . $e->getMessage() . "\n");
    exit(1);
}

// Upsert: falls Key existiert UPDATE, sonst INSERT. ON DUPLICATE KEY UPDATE
// stolpert hier nicht, weil setting_key unique ist.
$stmt = $pdo->prepare(
    'INSERT INTO settings (setting_key, setting_value, updated_at)
     VALUES (:k, :v, NOW())
     ON DUPLICATE KEY UPDATE setting_value = :v2, updated_at = NOW()'
);
$stmt->execute(['k' => $key, 'v' => $value, 'v2' => $value]);

echo "OK: $key = $value\n";
exit(0);
