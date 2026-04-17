<?php

declare(strict_types=1);

/**
 * import-strato-db.php
 *
 * Importiert eine phpMyAdmin-Export-Datei in die lokale Dev-DB.
 * Details siehe docs/Testumgebung.md.
 *
 * Nutzung:
 *   php scripts/import-strato-db.php [pfad-zum-dump.sql] [--force]
 *
 * Defaults via ENV: STRATO_DUMP_PATH, DB_HOST, DB_USERNAME, DB_PASSWORD, DB_DATABASE.
 */

// -----------------------------------------------------------------------
// Konstanten
// -----------------------------------------------------------------------
const ALLOWED_DB_HOSTS       = ['127.0.0.1', 'localhost', '::1'];
const IDENTIFIER_REGEX       = '/^[A-Za-z0-9_]{1,64}$/';
const PREFLIGHT_SCAN_BYTES   = 16384;
const DEV_SERVER_PORT        = 8000;
const BACKUP_GENERATIONS_KEEP = 3;

// -----------------------------------------------------------------------
// Argumente / Umgebung
// -----------------------------------------------------------------------
$host     = getenv('DB_HOST')        ?: '127.0.0.1';
$port     = (int) (getenv('DB_PORT') ?: 3306);
$user     = getenv('DB_USERNAME')    ?: 'root';
$pass     = getenv('DB_PASSWORD')    ?: '';
$dbName   = getenv('DB_DATABASE')    ?: 'vaes';
$dumpPath = $argv[1] ?? (getenv('STRATO_DUMP_PATH') ?: '');
$force    = in_array('--force', $argv, true);

echo "=== VAES Strato-DB-Import ===\n\n";

try {
    // -------------------------------------------------------------------
    // 1. Guards
    // -------------------------------------------------------------------
    assertHostAllowed($host);
    validateIdentifier($dbName, 'DB_DATABASE');
    echo "[1/7] DB-Host '$host' - Allowlist OK. DB-Name valide.\n";

    assertDumpFileValid($dumpPath, $force);
    echo "[2/7] Dump gefunden: $dumpPath ("
        . round(filesize($dumpPath) / 1024 / 1024, 1) . " MB).\n";

    // -------------------------------------------------------------------
    // 2. MySQL-CLI
    // -------------------------------------------------------------------
    $mysqlExe = findMysqlCli();
    if ($mysqlExe === null) {
        throw new RuntimeException(
            "mysql.exe / mysql nicht gefunden.\n"
            . "Bitte WAMP/XAMPP installieren oder mysql ins PATH setzen.\n"
            . "Gesuchte Pfade:\n"
            . "  - PATH\n"
            . "  - C:\\wamp64\\bin\\mysql\\mysql*\\bin\\\n"
            . "  - C:\\xampp\\mysql\\bin\\\n"
            . "  - C:\\Program Files\\MySQL\\MySQL Server *\\bin\\"
        );
    }
    echo "[3/7] mysql-CLI: $mysqlExe\n";

    // -------------------------------------------------------------------
    // 3. Dev-Server-Check
    // -------------------------------------------------------------------
    if (isPortOpen('127.0.0.1', DEV_SERVER_PORT)) {
        if (!$force) {
            throw new RuntimeException(
                'Port ' . DEV_SERVER_PORT . ' ist belegt - Dev-Server laeuft moeglicherweise.'
                . "\nBitte Dev-Server stoppen oder mit --force ueberspringen."
            );
        }
        echo "[4/7] Dev-Server laeuft (ignoriert wegen --force).\n";
    } else {
        echo "[4/7] Port " . DEV_SERVER_PORT . " frei - Dev-Server aus.\n";
    }

    // -------------------------------------------------------------------
    // 4. Backup + Import
    // -------------------------------------------------------------------
    $pdo = connectPdo($host, $port, $user, $pass);

    $timestamp = date('Ymd_His');
    $backupDb  = "{$dbName}_backup_{$timestamp}";
    validateIdentifier($backupDb, 'Backup-DB-Name');

    $backupCreated = performBackup($pdo, $mysqlExe, $dbName, $backupDb, $host, $port, $user, $pass);
    echo $backupCreated
        ? "[5/7] Backup angelegt: $backupDb\n"
        : "[5/7] Kein Backup (keine bestehende DB oder mysqldump nicht gefunden).\n";

    recreateDatabase($pdo, $dbName);
    echo "[6/7] DB '$dbName' neu angelegt.\n";

    echo "[7/7] Import laeuft ... (das kann dauern)\n";
    $rc = performImport($mysqlExe, $dumpPath, $dbName, $host, $port, $user, $pass);
    if ($rc !== 0) {
        // Import fehlgeschlagen -> leeren Backup-Container entsorgen
        if ($backupCreated) {
            dropDatabaseSilently($pdo, $backupDb);
        }
        throw new RuntimeException("Import brach mit Exit-Code $rc ab.");
    }

    echo "\nOK - Import abgeschlossen.\n";

    // -------------------------------------------------------------------
    // 5. Cleanup
    // -------------------------------------------------------------------
    cleanupOldBackups($pdo, $dbName, BACKUP_GENERATIONS_KEEP);

    echo "\nNaechste Schritte:\n";
    echo "  1. php scripts/anonymize-db.php\n";
    echo "  2. php scripts/seed-test-users.php\n";
    echo "  3. (im src/) composer setup:test-db\n";
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
            . 'Dieses Script darf NUR gegen eine lokale DB laufen.'
        );
    }
}

function validateIdentifier(string $value, string $label): void
{
    if (!preg_match(IDENTIFIER_REGEX, $value)) {
        throw new RuntimeException(
            "$label '$value' enthaelt ungueltige Zeichen. "
            . 'Erlaubt: Buchstaben, Ziffern, Unterstrich (max. 64).'
        );
    }
}

function assertDumpFileValid(string $dumpPath, bool $force): void
{
    if ($dumpPath === '') {
        throw new RuntimeException(
            "Kein Dump-Pfad uebergeben.\n"
            . "Nutzung: php scripts/import-strato-db.php <dump.sql>\n"
            . 'Oder ENV STRATO_DUMP_PATH setzen.'
        );
    }
    if (!is_file($dumpPath)) {
        throw new RuntimeException("Dump-Datei '$dumpPath' nicht gefunden.");
    }

    $head = (string) file_get_contents($dumpPath, false, null, 0, PREFLIGHT_SCAN_BYTES);
    if (stripos($head, 'CREATE TABLE') === false && !$force) {
        throw new RuntimeException(
            "Dump enthaelt in den ersten " . PREFLIGHT_SCAN_BYTES . " Bytes kein 'CREATE TABLE'.\n"
            . "Beim phpMyAdmin-Export 'Struktur und Daten' auswaehlen.\n"
            . 'Alternativ: Aufruf mit --force, um trotzdem zu importieren.'
        );
    }
}

// ========================================================================
// DB-Operationen
// ========================================================================

function connectPdo(string $host, int $port, string $user, string $pass): PDO
{
    return new PDO(
        "mysql:host=$host;port=$port;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

function recreateDatabase(PDO $pdo, string $dbName): void
{
    $pdo->exec("DROP DATABASE IF EXISTS `$dbName`");
    $pdo->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

function dropDatabaseSilently(PDO $pdo, string $dbName): void
{
    try {
        $pdo->exec("DROP DATABASE IF EXISTS `$dbName`");
    } catch (Throwable) {
        // best effort cleanup
    }
}

function databaseExists(PDO $pdo, string $dbName): bool
{
    $stmt = $pdo->prepare(
        'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :n'
    );
    $stmt->execute(['n' => $dbName]);
    return $stmt->fetch() !== false;
}

// ========================================================================
// Backup
// ========================================================================

function performBackup(
    PDO $pdo,
    string $mysqlExe,
    string $sourceDb,
    string $backupDb,
    string $host,
    int $port,
    string $user,
    string $pass
): bool {
    if (!databaseExists($pdo, $sourceDb)) {
        return false;
    }

    $mysqldumpExe = findMysqldumpBeside($mysqlExe);
    if ($mysqldumpExe === null) {
        return false;
    }

    $pdo->exec("CREATE DATABASE `$backupDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $cmd = buildBackupPipeCommand(
        $mysqldumpExe, $mysqlExe, $sourceDb, $backupDb, $host, $port, $user, $pass
    );

    passthru($cmd, $bcode);
    if ($bcode !== 0) {
        fwrite(STDERR, "  ! Backup-Pipe endete mit Code $bcode\n");
        dropDatabaseSilently($pdo, $backupDb);
        return false;
    }
    return true;
}

function findMysqldumpBeside(string $mysqlExe): ?string
{
    $dir = dirname($mysqlExe);
    foreach (['mysqldump.exe', 'mysqldump'] as $name) {
        $candidate = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return null;
}

function buildBackupPipeCommand(
    string $mysqldumpExe,
    string $mysqlExe,
    string $sourceDb,
    string $backupDb,
    string $host,
    int $port,
    string $user,
    string $pass
): string {
    $passOpt = buildPasswordOption($pass);
    $tpl = '%s -h%s -P%d -u%s %s --routines --triggers %s | %s -h%s -P%d -u%s %s %s';
    return sprintf(
        $tpl,
        escapeshellarg($mysqldumpExe), escapeshellarg($host), $port,
        escapeshellarg($user), $passOpt, escapeshellarg($sourceDb),
        escapeshellarg($mysqlExe), escapeshellarg($host), $port,
        escapeshellarg($user), $passOpt, escapeshellarg($backupDb)
    );
}

// ========================================================================
// Import
// ========================================================================

function performImport(
    string $mysqlExe,
    string $dumpPath,
    string $dbName,
    string $host,
    int $port,
    string $user,
    string $pass
): int {
    $cmd = buildImportCommand($mysqlExe, $dumpPath, $dbName, $host, $port, $user, $pass);
    passthru($cmd, $rc);
    return (int) $rc;
}

function buildImportCommand(
    string $mysqlExe,
    string $dumpPath,
    string $dbName,
    string $host,
    int $port,
    string $user,
    string $pass
): string {
    // Einheitlicher Pfad fuer Unix und Windows:
    // - passthru() laesst PHP den System-Shell aufrufen (sh/cmd.exe).
    // - Beide Shells verstehen '<' als stdin-Redirection.
    // - escapeshellarg() quotet betriebssystem-korrekt (Unix: einfache,
    //   Windows: doppelte Anfuehrungszeichen).
    // Damit ist Command-Injection durch manipulierte ENV-Variablen
    // (DB_HOST, DB_USERNAME, DB_PASSWORD, DB_DATABASE) ausgeschlossen.
    $passOpt = buildPasswordOption($pass);

    return sprintf(
        '%s -h%s -P%d -u%s %s --default-character-set=utf8mb4 %s < %s',
        escapeshellarg($mysqlExe), escapeshellarg($host), $port,
        escapeshellarg($user), $passOpt, escapeshellarg($dbName),
        escapeshellarg($dumpPath)
    );
}

function buildPasswordOption(string $pass): string
{
    // HINWEIS: Passwort auf Command-Line ist in Task-Manager/ps sichtbar.
    // Best Practice waere --defaults-extra-file; fuer lokale Dev-Env akzeptiert.
    return $pass !== '' ? ('-p' . escapeshellarg($pass)) : '';
}

// ========================================================================
// MySQL-CLI Auto-Detect
// ========================================================================

function findMysqlCli(): ?string
{
    foreach (searchMysqlPaths() as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * @return iterable<string>
 */
function searchMysqlPaths(): iterable
{
    // 1. PATH
    $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
    $out = [];
    @exec("$which mysql 2>" . (PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null'), $out);
    foreach ($out as $line) {
        $line = trim($line);
        if ($line !== '') yield $line;
    }

    // 2. WAMP
    foreach (glob('C:\\wamp64\\bin\\mysql\\mysql*\\bin\\mysql.exe') ?: [] as $p) {
        yield $p;
    }

    // 3. XAMPP
    yield 'C:\\xampp\\mysql\\bin\\mysql.exe';

    // 4. MySQL-Server
    foreach (glob('C:\\Program Files\\MySQL\\MySQL Server *\\bin\\mysql.exe') ?: [] as $p) {
        yield $p;
    }

    // 5. Linux / macOS
    yield '/usr/bin/mysql';
    yield '/usr/local/bin/mysql';
    yield '/opt/homebrew/bin/mysql';
}

// ========================================================================
// Kleine Helfer
// ========================================================================

function isPortOpen(string $host, int $port): bool
{
    $conn = @fsockopen($host, $port, $errno, $errstr, 1);
    if (is_resource($conn)) {
        fclose($conn);
        return true;
    }
    return false;
}

function cleanupOldBackups(PDO $pdo, string $baseName, int $keep): void
{
    $stmt = $pdo->prepare(
        'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA
         WHERE SCHEMA_NAME LIKE :p ORDER BY SCHEMA_NAME DESC'
    );
    $stmt->execute(['p' => $baseName . '_backup_%']);
    $backups = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $obsolete = array_slice($backups, $keep);
    foreach ($obsolete as $b) {
        $pdo->exec("DROP DATABASE `$b`");
        echo "  - Altes Backup entfernt: $b\n";
    }
}
