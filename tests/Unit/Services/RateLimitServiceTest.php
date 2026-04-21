<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\RateLimitService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für RateLimitService
 *
 * Verwendet PDO-Mocks, da der Service MySQL-spezifisches SQL
 * (DATE_SUB, INTERVAL) nutzt, das mit SQLite nicht kompatibel ist.
 */
class RateLimitServiceTest extends TestCase
{
    private \PDO&MockObject $pdo;
    private RateLimitService $service;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(\PDO::class);
        $this->service = new RateLimitService($this->pdo);
    }

    // =========================================================================
    // isAllowed()
    // =========================================================================

    /** @test */
    public function is_allowed_bei_keinen_eintraegen(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(0);

        $this->pdo->method('prepare')->willReturn($stmt);

        $this->assertTrue(
            $this->service->isAllowed('192.168.1.1', 'login', 5, 900)
        );
    }

    /** @test */
    public function is_allowed_true_unter_limit(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(3);

        $this->pdo->method('prepare')->willReturn($stmt);

        $this->assertTrue(
            $this->service->isAllowed('192.168.1.1', 'login', 5, 900)
        );
    }

    /** @test */
    public function is_allowed_false_bei_erreichtem_limit(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(5);

        $this->pdo->method('prepare')->willReturn($stmt);

        $this->assertFalse(
            $this->service->isAllowed('192.168.1.1', 'login', 5, 900)
        );
    }

    /** @test */
    public function is_allowed_false_bei_ueberschrittenem_limit(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(10);

        $this->pdo->method('prepare')->willReturn($stmt);

        $this->assertFalse(
            $this->service->isAllowed('192.168.1.1', 'login', 5, 900)
        );
    }

    /** @test */
    public function is_allowed_grenzwert_genau_eins_unter_limit(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(4); // 4 < 5

        $this->pdo->method('prepare')->willReturn($stmt);

        $this->assertTrue(
            $this->service->isAllowed('192.168.1.1', 'login', 5, 900)
        );
    }

    /** @test */
    public function is_allowed_prueft_korrekte_parameter(): void
    {
        // Count-Stmt: erwartet die drei isAllowed-Parameter
        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->expects($this->once())
            ->method('execute')
            ->with([
                'ip' => '10.0.0.1',
                'endpoint' => 'forgot-password',
                'window' => 900,
            ]);
        $countStmt->method('fetchColumn')->willReturn(0);

        // Cleanup-Stmt: darf auftreten (10% Wahrscheinlichkeit in isAllowed),
        // soll aber diesen Test nicht stoeren.
        $cleanupStmt = $this->createMock(\PDOStatement::class);

        $this->pdo->method('prepare')
            ->willReturnCallback(fn (string $sql) => str_starts_with(ltrim($sql), 'DELETE')
                ? $cleanupStmt
                : $countStmt);

        $this->assertTrue(
            $this->service->isAllowed('10.0.0.1', 'forgot-password', 5, 900)
        );
    }

    // =========================================================================
    // recordAttempt()
    // =========================================================================

    /** @test */
    public function record_attempt_fuehrt_insert_aus(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([
                'ip' => '192.168.1.1',
                'endpoint' => 'login',
            ]);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO rate_limits'))
            ->willReturn($stmt);

        $this->service->recordAttempt('192.168.1.1', 'login');
    }

    /** @test */
    public function record_attempt_mit_ipv6(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([
                'ip' => '2001:db8::1',
                'endpoint' => 'setup-password',
            ]);

        $this->pdo->method('prepare')->willReturn($stmt);

        $this->service->recordAttempt('2001:db8::1', 'setup-password');
    }

    // =========================================================================
    // Cleanup (probabilistisch - schwer zu testen, daher Struktur-Test)
    // =========================================================================

    /** @test */
    public function is_allowed_nutzt_prepared_statements(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(0);

        // Mindestens ein prepare-Aufruf (isAllowed oder cleanup)
        $this->pdo->expects($this->atLeastOnce())
            ->method('prepare')
            ->willReturn($stmt);

        $this->service->isAllowed('10.0.0.1', 'login', 5, 900);
    }

    // =========================================================================
    // Verschiedene Endpoints und IPs (Logik-Tests)
    // =========================================================================

    /** @test */
    public function verschiedene_endpoints_koennen_unterschiedliche_limits_haben(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(4);
        $stmt->method('execute');

        $this->pdo->method('prepare')->willReturn($stmt);

        // 4 Versuche: bei Limit 5 erlaubt, bei Limit 3 blockiert
        $this->assertTrue($this->service->isAllowed('10.0.0.1', 'login', 5, 900));
        $this->assertFalse($this->service->isAllowed('10.0.0.1', 'forgot-password', 3, 900));
    }

    // =========================================================================
    // isAllowedForEmail() / recordAttemptForEmail()
    //
    // Email-Bucket ist der Anti-Flood-Schutz fuer das Postfach eines Opfers:
    // unabhaengig davon, aus wie vielen IPs die Forgot-Password-Requests kommen,
    // sollen pro Empfaenger-Adresse im Zeitfenster nur N Mails rausgehen.
    // =========================================================================

    /** @test */
    public function is_allowed_for_email_true_bei_keinen_eintraegen(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(0);

        $this->pdo->method('prepare')->willReturn($stmt);

        $this->assertTrue(
            $this->service->isAllowedForEmail('opfer@example.com', 'forgot-password', 3, 3600)
        );
    }

    /** @test */
    public function is_allowed_for_email_false_bei_erreichtem_limit(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute');
        $stmt->method('fetchColumn')->willReturn(3);

        $this->pdo->method('prepare')->willReturn($stmt);

        $this->assertFalse(
            $this->service->isAllowedForEmail('opfer@example.com', 'forgot-password', 3, 3600)
        );
    }

    /** @test */
    public function is_allowed_for_email_prueft_korrekte_parameter(): void
    {
        // Der Count-Query fuer den Email-Bucket muss auf email + endpoint + window
        // filtern — nicht auf ip_address. Sonst wird der verteilte Angriff aus
        // vielen IPs nicht als ein Bucket erkannt.
        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->expects($this->once())
            ->method('execute')
            ->with([
                'email' => 'opfer@example.com',
                'endpoint' => 'forgot-password',
                'window' => 3600,
            ]);
        $countStmt->method('fetchColumn')->willReturn(0);

        $cleanupStmt = $this->createMock(\PDOStatement::class);

        $this->pdo->method('prepare')
            ->willReturnCallback(fn (string $sql) => str_starts_with(ltrim($sql), 'DELETE')
                ? $cleanupStmt
                : $countStmt);

        $this->assertTrue(
            $this->service->isAllowedForEmail('opfer@example.com', 'forgot-password', 3, 3600)
        );
    }

    /** @test */
    public function record_attempt_for_email_schreibt_ip_und_email_in_eine_zeile(): void
    {
        // Ein einzelner INSERT mit gefuellter email-Spalte zaehlt sowohl fuer
        // den IP-Bucket als auch fuer den Email-Bucket — kein Doppel-Write.
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([
                'ip' => '10.0.0.1',
                'email' => 'opfer@example.com',
                'endpoint' => 'forgot-password',
            ]);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO rate_limits'))
            ->willReturn($stmt);

        $this->service->recordAttemptForEmail('10.0.0.1', 'opfer@example.com', 'forgot-password');
    }
}
