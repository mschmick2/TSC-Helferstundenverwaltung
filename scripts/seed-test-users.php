<?php

declare(strict_types=1);

/**
 * seed-test-users.php
 *
 * Legt drei deterministische Testbenutzer in der lokalen VAES-DB an:
 *   - admin@vaes.test      (Rollen: administrator, mitglied)
 *   - pruefer@vaes.test    (Rollen: pruefer, mitglied)
 *   - mitglied@vaes.test   (Rolle:  mitglied)
 *
 * Alle mit Passwort aus TEST_USER_PASSWORD (Default: TestPass123!).
 * 2FA ist deaktiviert. Script ist idempotent (INSERT ... ON DUPLICATE KEY UPDATE).
 *
 * GUARDS:
 *   - DB_HOST muss 127.0.0.1 / localhost sein
 *   - config['app']['url'] darf nicht 'strato' enthalten, falls config.php existiert
 *   - Passwort-Hash wird zur Laufzeit via password_hash() generiert - kein Hash im Repo
 *
 * HINWEIS zum Audit-Log:
 *   Diese Script laeuft ausserhalb der Slim-App und nutzt PDO direkt,
 *   ohne AuditService::log(). Das ist bewusst - Test-Daten-Setup ist keine
 *   Business-Aktion im Sinne des Audit-Trails. Waere analog zum Anlegen von
 *   Testdaten per DB-Admin-Tool. Die audit_log_no_update/no_delete-Trigger
 *   bleiben aktiv; es werden keine Audit-Eintraege eingefuegt.
 *
 * Nutzung:
 *   php scripts/seed-test-users.php
 *   TEST_USER_PASSWORD=Custom! php scripts/seed-test-users.php
 */

const ALLOWED_DB_HOSTS       = ['127.0.0.1', 'localhost', '::1'];
const IDENTIFIER_REGEX       = '/^[A-Za-z0-9_]{1,64}$/';
const DEFAULT_TEST_PASSWORD  = 'TestPass123!';
const DEFAULT_EINTRITTSDATUM = '2020-01-01';
const BCRYPT_COST            = 12;
const PROD_HINT_SUBSTR       = 'strato';

$host     = getenv('DB_HOST')        ?: '127.0.0.1';
$port     = (int) (getenv('DB_PORT') ?: 3306);
$user     = getenv('DB_USERNAME')    ?: 'root';
$pass     = getenv('DB_PASSWORD')    ?: '';
$dbName   = getenv('DB_DATABASE')    ?: 'vaes';
$password = getenv('TEST_USER_PASSWORD') ?: DEFAULT_TEST_PASSWORD;

echo "=== VAES Test-User-Seed ===\n\n";

// --- Guard: DB-Host ------------------------------------------------------
if (!in_array($host, ALLOWED_DB_HOSTS, true)) {
    fwrite(STDERR, "ABBRUCH: DB_HOST '$host' nicht in Allowlist.\n");
    exit(2);
}

// --- Guard: Identifier ---------------------------------------------------
if (!preg_match(IDENTIFIER_REGEX, $dbName)) {
    fwrite(STDERR, "ABBRUCH: DB_DATABASE '$dbName' enthaelt ungueltige Zeichen.\n");
    exit(2);
}

// --- Guard: config.php nicht Produktion ----------------------------------
$configPath = __DIR__ . '/../src/config/config.php';
if (is_file($configPath)) {
    $config = require $configPath;
    $appUrl = strtolower((string) ($config['app']['url'] ?? ''));
    $dbHost = strtolower((string) ($config['database']['host'] ?? ''));
    if (str_contains($appUrl, PROD_HINT_SUBSTR) || str_contains($dbHost, PROD_HINT_SUBSTR)) {
        fwrite(STDERR, "ABBRUCH: config.php verweist auf Strato-Produktion.\n");
        fwrite(STDERR, "  app.url:       $appUrl\n");
        fwrite(STDERR, "  database.host: $dbHost\n");
        exit(2);
    }
}

// --- Verbindung ----------------------------------------------------------
try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "ABBRUCH: DB-Verbindung fehlgeschlagen: " . $e->getMessage() . "\n");
    exit(1);
}

// --- Rollen-IDs auflesen -------------------------------------------------
$roleIds = [];
foreach ($pdo->query('SELECT id, name FROM roles')->fetchAll() as $row) {
    $roleIds[$row['name']] = (int) $row['id'];
}

$expectedRoles = ['mitglied', 'pruefer', 'administrator'];
$missing = array_diff($expectedRoles, array_keys($roleIds));
if ($missing) {
    fwrite(STDERR, "ABBRUCH: Rollen fehlen in DB: " . implode(', ', $missing) . "\n");
    fwrite(STDERR, "Bitte scripts/database/create_database.sql einspielen.\n");
    exit(1);
}

// --- Passwort-Hash zur Laufzeit ------------------------------------------
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
echo "Passwort fuer Testuser: $password (bcrypt cost=" . BCRYPT_COST . ")\n\n";

// --- Testuser-Definition -------------------------------------------------
$users = [
    [
        'mitgliedsnummer' => 'TEST-ADMIN',
        'email'           => 'admin@vaes.test',
        'vorname'         => 'Test',
        'nachname'        => 'Administrator',
        'roles'           => ['administrator', 'mitglied'],
    ],
    [
        'mitgliedsnummer' => 'TEST-PRUEFER',
        'email'           => 'pruefer@vaes.test',
        'vorname'         => 'Test',
        'nachname'        => 'Pruefer',
        'roles'           => ['pruefer', 'mitglied'],
    ],
    [
        'mitgliedsnummer' => 'TEST-MITGLIED',
        'email'           => 'mitglied@vaes.test',
        'vorname'         => 'Test',
        'nachname'        => 'Mitglied',
        'roles'           => ['mitglied'],
    ],
];

$sqlUpsert = <<<SQL
INSERT INTO users (
    mitgliedsnummer, email, password_hash, vorname, nachname,
    is_active, totp_enabled, email_2fa_enabled,
    email_verified_at, password_changed_at, failed_login_attempts,
    eintrittsdatum
)
VALUES (
    :mitgliedsnummer, :email, :hash, :vorname, :nachname,
    1, 0, 0,
    NOW(), NOW(), 0,
    :eintrittsdatum
)
ON DUPLICATE KEY UPDATE
    password_hash         = VALUES(password_hash),
    vorname               = VALUES(vorname),
    nachname              = VALUES(nachname),
    is_active             = 1,
    totp_enabled          = 0,
    email_2fa_enabled     = 0,
    failed_login_attempts = 0,
    locked_until          = NULL,
    deleted_at            = NULL
SQL;

$stmtUpsert  = $pdo->prepare($sqlUpsert);
$stmtSelect  = $pdo->prepare('SELECT id FROM users WHERE email = :email');
$stmtRoleIns = $pdo->prepare(
    'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)'
);

foreach ($users as $u) {
    $stmtUpsert->execute([
        'mitgliedsnummer' => $u['mitgliedsnummer'],
        'email'           => $u['email'],
        'hash'            => $hash,
        'vorname'         => $u['vorname'],
        'nachname'        => $u['nachname'],
        'eintrittsdatum'  => DEFAULT_EINTRITTSDATUM,
    ]);

    $stmtSelect->execute(['email' => $u['email']]);
    $userId = (int) $stmtSelect->fetchColumn();

    // Alte Rollen-Zuweisungen entfernen (idempotent)
    $pdo->prepare('DELETE FROM user_roles WHERE user_id = :uid')
        ->execute(['uid' => $userId]);

    foreach ($u['roles'] as $roleName) {
        $stmtRoleIns->execute([
            'user_id' => $userId,
            'role_id' => $roleIds[$roleName],
        ]);
    }

    echo sprintf(
        "  ✓ %-25s  ID=%d  Rollen=%s\n",
        $u['email'], $userId, implode(',', $u['roles'])
    );
}

echo "\n✓ 3 Testbenutzer seeded. Login: <email> / $password\n";
exit(0);
