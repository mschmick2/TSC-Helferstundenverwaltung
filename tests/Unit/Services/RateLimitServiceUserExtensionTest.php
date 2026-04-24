<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\RateLimitService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration-Light-Tests fuer die neuen User-Bucket-Methoden in
 * RateLimitService (Modul 6 I8 Phase 2 / FU-G4-1).
 *
 * Scope-Grenze: die `isAllowedForUser`-Methode nutzt MySQL-spezifische
 * DATE_SUB(NOW(), INTERVAL :p SECOND)-Syntax, die SQLite nicht parsed.
 * Wir testen hier deshalb NUR das Insert-Verhalten
 * (`recordAttemptForUser`) ueber SQLite-PDO. Das Count-/Filter-Verhalten
 * der isAllowedForUser-Methode ist statisch abgesichert durch den
 * RateLimitWiringInvariantsTest (Regex auf das SQL-Muster) und
 * funktional durch den RateLimitMiddlewareTest (Mock auf die Service-
 * Ebene, verifiziert Call-Signatur und Bucket-Parameter). Die
 * MySQL-Laufzeit-Semantik landet erst in der Integration-Suite
 * (Phase 3 oder spaeter).
 */
final class RateLimitServiceUserExtensionTest extends TestCase
{
    private PDO $pdo;
    private RateLimitService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address TEXT NOT NULL,
                email TEXT NULL,
                endpoint TEXT NOT NULL,
                attempted_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S', 'now'))
            )"
        );
        $this->service = new RateLimitService($this->pdo);
    }

    public function test_recordAttemptForUser_stores_user_prefix_in_email_column(): void
    {
        $this->service->recordAttemptForUser(7, '203.0.113.5', 'tree_action');

        $stmt = $this->pdo->query(
            "SELECT ip_address, email, endpoint FROM rate_limits"
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertSame(
            '203.0.113.5',
            $row['ip_address'],
            'Die IP wird mitgespeichert -- Forensik fuer Multi-IP-User.'
        );
        self::assertSame(
            'user:7',
            $row['email'],
            'User-Key wird in der email-Spalte mit user:<id>-Prefix '
            . 'abgelegt (Architect Q6).'
        );
        self::assertSame(
            'tree_action',
            $row['endpoint'],
            'Bucket-Name wird in endpoint abgelegt.'
        );
    }

    public function test_recordAttemptForUser_creates_one_row_per_call(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->service->recordAttemptForUser(42, '127.0.0.1', 'edit_session_heartbeat');
        }

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM rate_limits WHERE email = 'user:42'"
        )->fetchColumn();
        self::assertSame(3, $count);
    }

    public function test_recordAttemptForUser_keys_different_users_separately(): void
    {
        $this->service->recordAttemptForUser(42, '127.0.0.1', 'tree_action');
        $this->service->recordAttemptForUser(42, '127.0.0.1', 'tree_action');
        $this->service->recordAttemptForUser(99, '127.0.0.1', 'tree_action');

        self::assertSame(
            2,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM rate_limits WHERE email = 'user:42'"
            )->fetchColumn()
        );
        self::assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM rate_limits WHERE email = 'user:99'"
            )->fetchColumn()
        );
    }

    public function test_recordAttemptForUser_keys_different_buckets_separately(): void
    {
        $this->service->recordAttemptForUser(42, '127.0.0.1', 'tree_action');
        $this->service->recordAttemptForUser(42, '127.0.0.1', 'edit_session_heartbeat');
        $this->service->recordAttemptForUser(42, '127.0.0.1', 'edit_session_heartbeat');

        self::assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM rate_limits "
                . "WHERE email = 'user:42' AND endpoint = 'tree_action'"
            )->fetchColumn()
        );
        self::assertSame(
            2,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM rate_limits "
                . "WHERE email = 'user:42' AND endpoint = 'edit_session_heartbeat'"
            )->fetchColumn()
        );
    }

    public function test_user_and_ip_methods_share_rate_limits_table(): void
    {
        // Konsistenz-Pruefung: die User-Methoden schreiben in dieselbe
        // Tabelle wie die bestehenden IP-/Email-Methoden. Das ist
        // bewusst so (Architect Q6, kein Schema-Upgrade).
        $this->service->recordAttempt('127.0.0.1', 'login');
        $this->service->recordAttemptForEmail(
            '127.0.0.1',
            'max@example.com',
            'forgot-password'
        );
        $this->service->recordAttemptForUser(42, '127.0.0.1', 'tree_action');

        $total = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM rate_limits"
        )->fetchColumn();
        self::assertSame(3, $total);

        // Keine Kollision: die email-Spalte haelt "max@example.com",
        // "user:42" und NULL nebeneinander.
        $emails = $this->pdo->query(
            "SELECT email FROM rate_limits ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([null, 'max@example.com', 'user:42'], $emails);
    }
}
