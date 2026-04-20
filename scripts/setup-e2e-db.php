<?php

declare(strict_types=1);

/**
 * setup-e2e-db.php
 *
 * Modul 8 I1: Legt die E2E-Test-DB `helferstunden_e2e` komplett neu an.
 *
 * Ablauf:
 *   1. DB droppen + neu anlegen
 *   2. scripts/database/create_database.sql einspielen
 *   3. Alle Migrationen aus scripts/database/migrations/*.sql in Dateinamen-
 *      Sortierung ausfuehren (ausser *.down.sql)
 *   4. 5 Seed-User anlegen (Admin, Pruefer, Event-Admin, 2x Mitglied)
 *      - 2FA deaktiviert
 *      - Passwort 'e2e-test-pw' (bcrypt cost=4 fuer Geschwindigkeit)
 *      - Rollen zugewiesen
 *
 * Verwendung:
 *   php scripts/setup-e2e-db.php
 *
 * Environment:
 *   DB_HOST             127.0.0.1
 *   DB_PORT             3306
 *   DB_USERNAME         root
 *   DB_PASSWORD         ""
 *   DB_E2E_DATABASE     helferstunden_e2e
 */

$host   = getenv('DB_HOST')         ?: '127.0.0.1';
$port   = (int) (getenv('DB_PORT')  ?: 3306);
$user   = getenv('DB_USERNAME')     ?: 'root';
$pass   = getenv('DB_PASSWORD')     ?: '';
$target = getenv('DB_E2E_DATABASE') ?: 'helferstunden_e2e';

$mysqlCli = getenv('MYSQL_CLI') ?: 'mysql';
// Windows/WAMP-Fallback, wenn mysql nicht im PATH
if (!commandExists($mysqlCli)) {
    $wampMysql = 'C:\\wamp64\\bin\\mysql\\mysql9.1.0\\bin\\mysql.exe';
    if (is_executable($wampMysql)) {
        $mysqlCli = $wampMysql;
    }
}

$repoRoot = dirname(__DIR__);
$schemaFile = $repoRoot . '/scripts/database/create_database.sql';
$migrationsDir = $repoRoot . '/scripts/database/migrations';

echo "=== VAES E2E-DB Setup ===\n";
echo "Host:   $host:$port\n";
echo "User:   $user\n";
echo "Ziel:   $target\n\n";

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "FEHLER: MySQL-Verbindung fehlgeschlagen: " . $e->getMessage() . "\n");
    exit(1);
}

// 1. DB droppen + neu anlegen
$pdo->exec("DROP DATABASE IF EXISTS `$target`");
$pdo->exec(
    "CREATE DATABASE `$target` DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci"
);
echo "✓ Ziel-DB '$target' frisch angelegt.\n";

$pdo->exec("USE `$target`");

// 2. Schema einspielen via mysql-CLI (kennt DELIMITER nativ)
if (!is_readable($schemaFile)) {
    fwrite(STDERR, "FEHLER: Schema-Datei '$schemaFile' nicht lesbar.\n");
    exit(1);
}
runMysqlFile($mysqlCli, $host, $port, $user, $pass, $target, $schemaFile);
echo "✓ Schema aus create_database.sql eingespielt.\n";

// 3. Migrationen einspielen (alphabetisch, nur *.sql ohne .down.)
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files);
$migrationsRun = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (str_contains($name, '.down.')) {
        continue;
    }
    runMysqlFile($mysqlCli, $host, $port, $user, $pass, $target, $file);
    $migrationsRun++;
    echo "  ✓ $name\n";
}
echo "✓ $migrationsRun Migration(en) eingespielt.\n";

// 4. Seed-User
$bcrypt = password_hash('e2e-test-pw', PASSWORD_BCRYPT, ['cost' => 4]);

$users = [
    [
        'email' => 'admin@e2e.local',
        'mnr'   => 'E2E-ADM',
        'vn'    => 'E2E',
        'nn'    => 'Admin',
        'roles' => ['administrator', 'pruefer', 'event_admin', 'mitglied'],
    ],
    [
        'email' => 'pruefer@e2e.local',
        'mnr'   => 'E2E-PRF',
        'vn'    => 'E2E',
        'nn'    => 'Pruefer',
        'roles' => ['pruefer', 'mitglied'],
    ],
    [
        'email' => 'event@e2e.local',
        'mnr'   => 'E2E-EVT',
        'vn'    => 'E2E',
        'nn'    => 'Eventadmin',
        'roles' => ['event_admin', 'mitglied'],
    ],
    [
        'email' => 'alice@e2e.local',
        'mnr'   => 'E2E-ALI',
        'vn'    => 'Alice',
        'nn'    => 'Mitglied',
        'roles' => ['mitglied'],
    ],
    [
        'email' => 'bob@e2e.local',
        'mnr'   => 'E2E-BOB',
        'vn'    => 'Bob',
        'nn'    => 'Mitglied',
        'roles' => ['mitglied'],
    ],
];

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

$insUser = $pdo->prepare(
    'INSERT INTO users (mitgliedsnummer, email, password_hash, vorname, nachname,
                        totp_enabled, email_2fa_enabled, is_active, email_verified_at,
                        eintrittsdatum, created_at)
     VALUES (:mnr, :email, :ph, :vn, :nn, 0, 0, 1, NOW(), :ed, NOW())'
);
$insRole = $pdo->prepare(
    'INSERT INTO user_roles (user_id, role_id, assigned_at)
     SELECT :uid, r.id, NOW() FROM roles r WHERE r.name = :role'
);

foreach ($users as $u) {
    $insUser->execute([
        'mnr'   => $u['mnr'],
        'email' => $u['email'],
        'ph'    => $bcrypt,
        'vn'    => $u['vn'],
        'nn'    => $u['nn'],
        'ed'    => '2020-01-01',
    ]);
    $uid = (int) $pdo->lastInsertId();
    foreach ($u['roles'] as $role) {
        $insRole->execute(['uid' => $uid, 'role' => $role]);
    }
    echo "  ✓ User {$u['email']} (ID $uid) mit Rollen [" . implode(',', $u['roles']) . "]\n";
}

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

echo "\n✓ E2E-DB '$target' bereit. Login-Passwort fuer alle Seed-User: 'e2e-test-pw'.\n";
exit(0);

/**
 * Pruefung, ob `$cmd` als ausfuehrbares Kommando im PATH aufloest.
 * Akzeptiert auch absolute Pfade.
 */
function commandExists(string $cmd): bool
{
    if (str_contains($cmd, DIRECTORY_SEPARATOR) || str_contains($cmd, '/')) {
        return @is_executable($cmd);
    }
    $which = stripos(PHP_OS, 'WIN') === 0 ? 'where' : 'which';
    $out = []; $rc = 1;
    @exec(escapeshellcmd($which) . ' ' . escapeshellarg($cmd) . ' 2>NUL', $out, $rc);
    return $rc === 0;
}

/**
 * Spielt eine SQL-Datei per mysql-CLI ein. Die CLI interpretiert DELIMITER
 * korrekt, sodass Trigger/Procedures ohne Klimmzuege laufen.
 */
function runMysqlFile(
    string $mysql,
    string $host,
    int $port,
    string $user,
    string $pass,
    string $database,
    string $file
): void {
    // SQL bereinigen: CREATE DATABASE / USE helferstunden raus, damit die
    // Datei in die uebergebene Ziel-DB schreibt statt in 'helferstunden'.
    $sql = (string) file_get_contents($file);
    $sql = preg_replace(
        '/^\s*CREATE\s+DATABASE[^;]+;\s*$/im',
        '-- (CREATE DATABASE entfernt fuer E2E)',
        $sql
    ) ?? $sql;
    $sql = preg_replace(
        '/^\s*USE\s+\S+;\s*$/im',
        '-- (USE entfernt fuer E2E)',
        $sql
    ) ?? $sql;

    $tmp = tempnam(sys_get_temp_dir(), 'vaes_e2e_') ?: throw new RuntimeException('tempnam failed');
    file_put_contents($tmp, $sql);

    try {
        // Passwort ueber Environment uebergeben, nicht als CLI-Argument
        if (stripos(PHP_OS, 'WIN') === 0) {
            // cmd.exe: set VAR=... && kommando
            $envPrefix = 'set "MYSQL_PWD=' . str_replace('"', '""', $pass) . '" && ';
        } else {
            $envPrefix = 'MYSQL_PWD=' . escapeshellarg($pass) . ' ';
        }
        $cmd = sprintf(
            '%s%s --host=%s --port=%d --user=%s --default-character-set=utf8mb4 %s < %s 2>&1',
            $envPrefix,
            escapeshellarg($mysql),
            escapeshellarg($host),
            $port,
            escapeshellarg($user),
            escapeshellarg($database),
            escapeshellarg($tmp)
        );

        $out = []; $rc = 0;
        exec($cmd, $out, $rc);
        if ($rc !== 0) {
            fwrite(STDERR, "FEHLER beim Einspielen von " . basename($file) . ":\n"
                . implode("\n", $out) . "\n");
            exit(1);
        }
        foreach ($out as $line) {
            if (trim($line) !== '' && stripos($line, 'Using a password') === false) {
                fwrite(STDERR, "mysql: $line\n");
            }
        }
    } finally {
        @unlink($tmp);
    }
}

/**
 * (Legacy) Fuehrt ein SQL-Skript mit moeglichen DELIMITER-Direktiven aus.
 * Nicht mehr genutzt, da der mysql-CLI-Weg robuster ist. Bleibt fuer Notfaelle.
 */
function runSqlScript(PDO $pdo, string $sql, string $label): void
{
    // Kommentare (einzeilig -- und #) entfernen, damit Trenner nicht falsch greifen
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

    // DELIMITER-Bloecke erkennen: MySQL-CLI-Syntax. PDO ignoriert DELIMITER.
    // Strategie: Bloecke zwischen DELIMITER $$ ... DELIMITER ; als einzelnes
    // Statement ausfuehren, den Rest statementweise.
    $segments = [];
    $currentDelim = ';';
    $buffer = '';
    $lines = preg_split('/\r\n|\r|\n/', $sql) ?: [];
    foreach ($lines as $line) {
        $trim = trim($line);
        if (preg_match('/^DELIMITER\s+(\S+)/i', $trim, $m)) {
            if ($buffer !== '') {
                $segments[] = ['delim' => $currentDelim, 'sql' => $buffer];
                $buffer = '';
            }
            $currentDelim = $m[1];
            continue;
        }
        $buffer .= $line . "\n";
    }
    if ($buffer !== '') {
        $segments[] = ['delim' => $currentDelim, 'sql' => $buffer];
    }

    foreach ($segments as $seg) {
        $delim = $seg['delim'];
        $chunk = $seg['sql'];
        if ($delim === ';') {
            // Statements normal splitten
            $parts = preg_split('/;\s*$/m', $chunk) ?: [];
            foreach ($parts as $stmt) {
                $stmt = trim($stmt);
                if ($stmt === '') continue;
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    fwrite(STDERR, "FEHLER in $label bei Statement:\n$stmt\n\n" . $e->getMessage() . "\n");
                    exit(1);
                }
            }
        } else {
            // Custom Delimiter (zB $$): als einzelnes Statement
            $parts = explode($delim, $chunk);
            foreach ($parts as $stmt) {
                $stmt = trim($stmt);
                if ($stmt === '') continue;
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    fwrite(STDERR, "FEHLER in $label bei Statement (delim=$delim):\n$stmt\n\n" . $e->getMessage() . "\n");
                    exit(1);
                }
            }
        }
    }
}
