<?php

declare(strict_types=1);

/**
 * anonymize-db.php
 *
 * Wrapper um scripts/anonymize-db.sql.
 * Haelt alle Sicherheitsguards in PHP (Host-Allowlist, Identifier-Validation,
 * Prod-Config-Detection); die SQL-Datei bleibt reine Daten-Mutation.
 *
 * Nutzung:
 *   php scripts/anonymize-db.php
 *
 * Defaults via ENV: DB_HOST, DB_USERNAME, DB_PASSWORD, DB_DATABASE.
 */

const ALLOWED_DB_HOSTS = ['127.0.0.1', 'localhost', '::1'];
const IDENTIFIER_REGEX = '/^[A-Za-z0-9_]{1,64}$/';
const SQL_FILE         = __DIR__ . '/anonymize-db.sql';
const PROD_HINT_SUBSTR = 'strato';

$host   = getenv('DB_HOST')        ?: '127.0.0.1';
$port   = (int) (getenv('DB_PORT') ?: 3306);
$user   = getenv('DB_USERNAME')    ?: 'root';
$pass   = getenv('DB_PASSWORD')    ?: '';
$dbName = getenv('DB_DATABASE')    ?: 'vaes';

echo "=== VAES DB-Anonymisierung ===\n\n";

try {
    assertHostAllowed($host);
    assertIdentifierValid($dbName, 'DB_DATABASE');
    assertConfigNotProduction();
    echo "[1/3] Guards OK (Host '$host', DB '$dbName').\n";

    if (!is_file(SQL_FILE)) {
        throw new RuntimeException('anonymize-db.sql nicht gefunden: ' . SQL_FILE);
    }
    $sql = (string) file_get_contents(SQL_FILE);
    echo "[2/3] SQL-Datei geladen (" . strlen($sql) . " Bytes).\n";

    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE           => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES  => true,   // noetig fuer Multi-Statement
        ]
    );

    echo "[3/3] Anonymisierung laeuft ...\n";
    // Multi-Statement SQL: prepare+execute+nextRowset drainiert saemtliche
    // Resultsets (z.B. die SELECT ... AS info-Zeilen in anonymize-db.sql),
    // damit die Connection fuer Folgequeries sauber bleibt.
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    do {
        // Ergebnisse verwerfen, nur Rowset-Wechsel sind wichtig.
        $stmt->fetchAll();
    } while ($stmt->nextRowset());

    echo "\nOK - Anonymisierung abgeschlossen.\n";
    echo "Naechster Schritt: php scripts/seed-test-users.php\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nFEHLER: " . $e->getMessage() . "\n");
    exit(1);
}

// ========================================================================
// Guards
// ========================================================================

function assertHostAllowed(string $host): void
{
    if (!in_array($host, ALLOWED_DB_HOSTS, true)) {
        throw new RuntimeException(
            "DB_HOST '$host' ist nicht in Allowlist ["
            . implode(', ', ALLOWED_DB_HOSTS) . "].\n"
            . 'Anonymisierung darf NUR gegen eine lokale DB laufen.'
        );
    }
}

function assertIdentifierValid(string $value, string $label): void
{
    if (!preg_match(IDENTIFIER_REGEX, $value)) {
        throw new RuntimeException(
            "$label '$value' enthaelt ungueltige Zeichen. "
            . 'Erlaubt: Buchstaben, Ziffern, Unterstrich (max. 64).'
        );
    }
}

function assertConfigNotProduction(): void
{
    $configPath = __DIR__ . '/../src/config/config.php';
    if (!is_file($configPath)) {
        return;
    }
    $config = require $configPath;
    $appUrl = strtolower((string) ($config['app']['url'] ?? ''));
    $dbHost = strtolower((string) ($config['database']['host'] ?? ''));
    if (str_contains($appUrl, PROD_HINT_SUBSTR) || str_contains($dbHost, PROD_HINT_SUBSTR)) {
        throw new RuntimeException(
            "config.php verweist auf Produktion:\n"
            . "  app.url:       $appUrl\n"
            . "  database.host: $dbHost\n"
            . 'Anonymisierung abgebrochen.'
        );
    }
}
