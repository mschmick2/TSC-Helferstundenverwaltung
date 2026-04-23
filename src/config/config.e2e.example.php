<?php
/**
 * VAES - Konfiguration fuer E2E-Tests (Modul 8)
 *
 * Vorlage. Kopieren zu 'config.e2e.php' (nicht im Git).
 * Der PHP-Dev-Server fuer Playwright liest diese Datei, wenn die Umgebungs-
 * variable `VAES_CONFIG_FILE` auf ihren Pfad zeigt.
 *
 * Unterschiede zur normalen config.php:
 *   - base_path leer   -> Dev-Server auf localhost:8001 ohne Subpfad
 *   - eigene Test-DB   -> `helferstunden_e2e`, wird bei jedem Lauf neu gebaut
 *   - MailPit          -> SMTP localhost:1025, catches alle Mails
 *   - debug = true     -> klare Fehler-Seiten statt Generic
 */

return [
    'app' => [
        'name' => 'VAES (E2E)',
        'version' => '1.4.5-e2e',
        'url' => 'http://localhost:8001',
        'base_path' => '',
        'debug' => true,
        'timezone' => 'Europe/Berlin',
        'locale' => 'de_DE',
    ],

    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'helferstunden_e2e',
        'user' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],

    'session' => [
        'name' => 'VAES_E2E_SESSION',
        'lifetime' => 1800,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ],

    'security' => [
        'bcrypt_cost' => 4,
        'max_login_attempts' => 20,
        'lockout_duration' => 60,
        'csrf_token_lifetime' => 3600,
        'require_2fa' => false,
        // Forgot-Password-Buckets bleiben strikt, damit die E2E-Spec
        // (tests/e2e/specs/09-password-reset-rate-limit.spec.ts) den Schutz
        // im kurzen Zeitfenster eines Test-Runs sichtbar machen kann.
        'forgot_password_rate_limit_max_per_ip' => 5,
        'forgot_password_rate_limit_window_per_ip' => 900,
        'forgot_password_rate_limit_max_per_email' => 3,
        'forgot_password_rate_limit_window_per_email' => 3600,
        'reset_password_rate_limit_max' => 10,
        'reset_password_rate_limit_window' => 900,
    ],

    'mail' => [
        'driver' => 'smtp',
        'host' => '127.0.0.1',
        'port' => 1025,
        'username' => '',
        'password' => '',
        'encryption' => '',
        'from' => [
            'address' => 'vaes-e2e@test.local',
            'name' => 'VAES E2E',
        ],
    ],

    '2fa' => [
        'totp' => [
            'issuer' => 'VAES-E2E',
            'digits' => 6,
            'period' => 30,
            'algorithm' => 'sha1',
        ],
        'email' => [
            'code_length' => 6,
            'expiry_minutes' => 10,
        ],
    ],

    'reminders' => [
        'enabled' => true,
        'days_before_reminder' => 7,
    ],

    'locks' => [
        'timeout_minutes' => 5,
        'check_interval_seconds' => 30,
    ],

    'paths' => [
        'storage' => __DIR__ . '/../storage',
        'logs' => __DIR__ . '/../storage/logs',
        'cache' => __DIR__ . '/../storage/cache',
        'uploads' => __DIR__ . '/../storage/uploads',
    ],

    'logging' => [
        'level' => 'warning',
        'max_files' => 30,
    ],

    'verein' => [
        'name' => 'TSC Mondial (E2E)',
        'logo_path' => null,
        'address' => [
            'street' => 'Teststrasse 1',
            'zip' => '10000',
            'city' => 'Testhausen',
        ],
    ],
];
