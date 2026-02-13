<?php
/**
 * VAES - Konfigurationsdatei (Beispiel)
 * 
 * Kopieren Sie diese Datei zu 'config.php' und passen Sie die Werte an.
 * Die Datei 'config.php' wird NICHT in Git versioniert!
 */

return [
    // ==========================================================================
    // Anwendung
    // ==========================================================================
    'app' => [
        'name' => 'VAES',
        'version' => '1.3.0',
        'url' => 'https://192.168.3.98/helferstunden',  // Volle URL inkl. Unterpfad (fuer E-Mail-Links)
        'base_path' => '/helferstunden',  // Unterpfad fuer Unterverzeichnis-Installation, '' fuer Root
        'debug' => false,  // Auf Produktion: IMMER false!
        'timezone' => 'Europe/Berlin',
        'locale' => 'de_DE',
    ],
    
    // ==========================================================================
    // Datenbank (MySQL 8.4)
    // ==========================================================================
    'database' => [
        'host' => 'rdbms.strato.de',  // Strato MySQL-Server
        'port' => 3306,
        'name' => 'DBxxxxxxxx',        // Ihre Datenbank-Name
        'user' => 'Uxxxxxxxx',         // Ihr Datenbank-Benutzer
        'password' => '',              // Ihr Datenbank-Passwort
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ],
    ],
    
    // ==========================================================================
    // Session
    // HINWEIS: Das 'secure'-Flag wird in index.php automatisch an HTTPS/HTTP
    // angepasst. Bei HTTP wird 'secure' automatisch auf false gesetzt.
    // ==========================================================================
    'session' => [
        'name' => 'VAES_SESSION',
        'lifetime' => 1800,  // 30 Minuten in Sekunden
        'path' => '/',
        'domain' => '',      // Leer = aktuelle Domain
        'secure' => true,    // Nur ueber HTTPS (wird in index.php auto-erkannt)
        'httponly' => true,   // Nicht per JavaScript zugreifbar
        'samesite' => 'Lax',
    ],
    
    // ==========================================================================
    // Sicherheit
    // ==========================================================================
    'security' => [
        'bcrypt_cost' => 12,
        'max_login_attempts' => 5,
        'lockout_duration' => 900,  // 15 Minuten in Sekunden
        'csrf_token_lifetime' => 3600,
        'require_2fa' => true,
    ],
    
    // ==========================================================================
    // E-Mail (SMTP)
    //
    // Telekom / T-Online:
    //   host: securesmtp.t-online.de, port: 587, encryption: tls
    //   username: Zugangsnummer (z.B. 123456789012) ODER vollstaendige E-Mail
    //   password: E-Mail-Passwort (NICHT das Telekom-Login-Passwort)
    //   from.address: MUSS eine bei T-Online registrierte Adresse sein
    //                 z.B. zugangsnummer@t-online.de
    //
    // Strato:
    //   host: smtp.strato.de, port: 587, encryption: tls
    //   username: Ihre Strato E-Mail-Adresse
    //   password: Ihr Strato E-Mail-Passwort
    //   from.address: Ihre Strato E-Mail-Adresse
    //
    // Alternativ (nur Telekom): Port 465 mit encryption: ssl
    // ==========================================================================
    'mail' => [
        'driver' => 'smtp',
        'host' => 'securesmtp.t-online.de',  // Telekom SMTP (Port 587/TLS)
        'port' => 587,
        'username' => '',                     // Telekom Zugangsnummer oder E-Mail
        'password' => '',                     // E-Mail-Passwort
        'encryption' => 'tls',               // TLS (STARTTLS) auf Port 587
        'from' => [
            'address' => '',                  // MUSS bei T-Online registriert sein!
            'name' => 'VAES System',
        ],
    ],
    
    // ==========================================================================
    // 2FA (Zwei-Faktor-Authentifizierung)
    // ==========================================================================
    '2fa' => [
        'totp' => [
            'issuer' => 'VAES',
            'digits' => 6,
            'period' => 30,
            'algorithm' => 'sha1',
        ],
        'email' => [
            'code_length' => 6,
            'expiry_minutes' => 10,
        ],
    ],
    
    // ==========================================================================
    // Erinnerungen
    // ==========================================================================
    'reminders' => [
        'enabled' => true,
        'days_before_reminder' => 7,  // Tage bis zur Erinnerungs-E-Mail
    ],
    
    // ==========================================================================
    // Bearbeitungssperren
    // ==========================================================================
    'locks' => [
        'timeout_minutes' => 5,
        'check_interval_seconds' => 30,
    ],
    
    // ==========================================================================
    // Dateipfade
    // ==========================================================================
    'paths' => [
        'storage' => __DIR__ . '/../storage',
        'logs' => __DIR__ . '/../storage/logs',
        'cache' => __DIR__ . '/../storage/cache',
        'uploads' => __DIR__ . '/../storage/uploads',
    ],
    
    // ==========================================================================
    // Logging
    // ==========================================================================
    'logging' => [
        'level' => 'warning',  // debug, info, warning, error
        'max_files' => 30,     // Tage aufbewahren
    ],
    
    // ==========================================================================
    // Verein (für Anzeige und PDF-Exporte)
    // ==========================================================================
    'verein' => [
        'name' => 'Mein Verein e.V.',
        'logo_path' => null,  // Pfad zum Logo (optional)
        'address' => [
            'street' => 'Musterstraße 1',
            'zip' => '12345',
            'city' => 'Musterstadt',
        ],
    ],
];
