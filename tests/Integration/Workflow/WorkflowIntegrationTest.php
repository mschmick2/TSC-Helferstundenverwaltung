<?php

declare(strict_types=1);

namespace Tests\Integration\Workflow;

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\WorkEntry;
use App\Repositories\DialogRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkEntryRepository;
use App\Services\AuditService;
use App\Services\EmailService;
use App\Services\WorkflowService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Integrationstests für den vollständigen Workflow-Lebenszyklus
 *
 * Testet realistische Szenarien über mehrere Statusübergänge hinweg.
 */
class WorkflowIntegrationTest extends TestCase
{
    private WorkflowService $service;
    private WorkEntryRepository&MockObject $entryRepo;
    private DialogRepository&MockObject $dialogRepo;
    private AuditService&MockObject $auditService;
    private EmailService&MockObject $emailService;
    private UserRepository&MockObject $userRepo;
    private LoggerInterface&MockObject $logger;

    // Test-Benutzer
    private User $mitglied;
    private User $pruefer1;
    private User $pruefer2;
    private User $admin;
    private User $erfasser;

    protected function setUp(): void
    {
        $this->entryRepo = $this->createMock(WorkEntryRepository::class);
        $this->dialogRepo = $this->createMock(DialogRepository::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->userRepo = $this->createMock(UserRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new WorkflowService(
            $this->entryRepo,
            $this->dialogRepo,
            $this->auditService,
            $this->emailService,
            $this->userRepo,
            $this->logger,
            'https://example.com'
        );

        // Test-Benutzer erstellen
        $this->mitglied = $this->createTestUser(10, ['mitglied']);
        $this->pruefer1 = $this->createTestUser(20, ['mitglied', 'pruefer']);
        $this->pruefer2 = $this->createTestUser(30, ['mitglied', 'pruefer']);
        $this->admin = $this->createTestUser(40, ['administrator']);
        $this->erfasser = $this->createTestUser(50, ['mitglied', 'erfasser']);

        // Default: updateStatus gelingt
        $this->entryRepo->method('updateStatus')->willReturn(true);
        $this->entryRepo->method('correctEntry')->willReturn(true);
        $this->userRepo->method('findByRole')->willReturn([]);
        $this->userRepo->method('findById')->willReturnCallback(function (int $id) {
            return match ($id) {
                10 => $this->mitglied,
                20 => $this->pruefer1,
                30 => $this->pruefer2,
                40 => $this->admin,
                50 => $this->erfasser,
                default => null,
            };
        });
    }

    private function createTestUser(int $id, array $roles): User
    {
        $user = User::fromArray([
            'id' => (string) $id,
            'vorname' => "User{$id}",
            'nachname' => 'Test',
            'email' => "user{$id}@test.de",
        ]);
        $user->setRoles($roles);
        return $user;
    }

    private function createEntry(
        string $status = 'entwurf',
        int $userId = 10,
        int $createdByUserId = 10
    ): WorkEntry {
        return WorkEntry::fromArray([
            'id' => '1',
            'entry_number' => '2025-00001',
            'status' => $status,
            'user_id' => (string) $userId,
            'created_by_user_id' => (string) $createdByUserId,
            'hours' => '4.5',
            'version' => '1',
        ]);
    }

    // =========================================================================
    // Szenario 1: Glückspfad (Entwurf → Eingereicht → Freigegeben)
    // =========================================================================

    /** @test */
    public function glueckspfad_entwurf_eingereicht_freigegeben(): void
    {
        // Schritt 1: Mitglied reicht ein
        $entry = $this->createEntry('entwurf');
        $this->assertTrue($this->service->submit($entry, $this->mitglied));

        // Schritt 2: Prüfer genehmigt
        $entry = $this->createEntry('eingereicht');
        $this->assertTrue($this->service->approve($entry, $this->pruefer1));
    }

    // =========================================================================
    // Szenario 2: Klärung (Entwurf → Eingereicht → In Klärung → Freigegeben)
    // =========================================================================

    /** @test */
    public function klaerungspfad_mit_rueckfrage(): void
    {
        // Schritt 1: Einreichung
        $entry = $this->createEntry('entwurf');
        $this->service->submit($entry, $this->mitglied);

        // Schritt 2: Prüfer stellt Rückfrage
        $entry = $this->createEntry('eingereicht');
        $this->dialogRepo->expects($this->once())->method('create');
        $this->service->returnForRevision($entry, $this->pruefer1, 'Bitte Datum prüfen');

        // Schritt 3: Nach Klärung → Freigabe
        $entry = $this->createEntry('in_klaerung');
        $this->assertTrue($this->service->approve($entry, $this->pruefer1));
    }

    // =========================================================================
    // Szenario 3: Ablehnung
    // =========================================================================

    /** @test */
    public function ablehnungspfad(): void
    {
        $entry = $this->createEntry('eingereicht');
        $this->assertTrue($this->service->reject($entry, $this->pruefer1, 'Nicht förderfähig'));
    }

    /** @test */
    public function ablehnung_von_in_klaerung(): void
    {
        $entry = $this->createEntry('in_klaerung');
        $this->assertTrue($this->service->reject($entry, $this->pruefer1, 'Klärung nicht zufriedenstellend'));
    }

    // =========================================================================
    // Szenario 4: Stornierung und Reaktivierung
    // =========================================================================

    /** @test */
    public function stornierung_und_reaktivierung(): void
    {
        // Stornieren
        $entry = $this->createEntry('eingereicht');
        $this->assertTrue($this->service->cancel($entry, $this->mitglied));

        // Reaktivieren
        $entry = $this->createEntry('storniert');
        $this->assertTrue($this->service->reactivate($entry, $this->mitglied));
    }

    // =========================================================================
    // Szenario 5: Korrektur nach Freigabe
    // =========================================================================

    /** @test */
    public function korrektur_durch_pruefer(): void
    {
        $entry = $this->createEntry('freigegeben');
        $this->assertTrue(
            $this->service->correct($entry, $this->pruefer1, 3.0, 'Tippfehler bei Stunden')
        );
    }

    /** @test */
    public function korrektur_durch_admin(): void
    {
        $entry = $this->createEntry('freigegeben');
        $this->assertTrue(
            $this->service->correct($entry, $this->admin, 2.5, 'Admin-Korrektur')
        );
    }

    // =========================================================================
    // Szenario 6: Selbstgenehmigung - Alle Varianten
    // =========================================================================

    /** @test */
    public function selbstgenehmigung_alle_varianten_blockiert(): void
    {
        // Fall 1: Prüfer genehmigt eigenen Antrag (user_id = prüfer_id)
        $ownEntry = $this->createEntry('eingereicht', 20, 20);
        try {
            $this->service->approve($ownEntry, $this->pruefer1);
            $this->fail('Selbstgenehmigung sollte blockiert sein');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Eigene', $e->getMessage());
        }

        // Fall 2: Erfasser prüft eigenen Fremdeintrag (created_by = prüfer_id)
        $erfasserEntry = $this->createEntry('eingereicht', 10, 20);
        try {
            $this->service->approve($erfasserEntry, $this->pruefer1);
            $this->fail('Erfasser-Selbstprüfung sollte blockiert sein');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Von Ihnen erstellte', $e->getMessage());
        }

        // Fall 3: Admin genehmigt eigenen Antrag
        $adminEntry = $this->createEntry('eingereicht', 40, 40);
        try {
            $this->service->approve($adminEntry, $this->admin);
            $this->fail('Admin-Selbstgenehmigung sollte blockiert sein');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Eigene', $e->getMessage());
        }
    }

    /** @test */
    public function selbstablehnung_blockiert(): void
    {
        $ownEntry = $this->createEntry('eingereicht', 20, 20);

        $this->expectException(BusinessRuleException::class);

        $this->service->reject($ownEntry, $this->pruefer1, 'Test');
    }

    /** @test */
    public function selbst_rueckfrage_blockiert(): void
    {
        $ownEntry = $this->createEntry('eingereicht', 20, 20);

        $this->expectException(BusinessRuleException::class);

        $this->service->returnForRevision($ownEntry, $this->pruefer1, 'Test');
    }

    // =========================================================================
    // Szenario 7: Endstatus-Schutz
    // =========================================================================

    /** @test */
    public function freigegeben_ist_endstatus(): void
    {
        $entry = $this->createEntry('freigegeben');

        $this->expectException(BusinessRuleException::class);

        $this->service->submit($entry, $this->mitglied);
    }

    /** @test */
    public function abgelehnt_ist_endstatus(): void
    {
        $entry = $this->createEntry('abgelehnt');

        // Keine Transition möglich
        $this->expectException(BusinessRuleException::class);

        $this->service->withdraw($entry, $this->mitglied);
    }

    // =========================================================================
    // Szenario 8: Erfasser-Workflow
    // =========================================================================

    /** @test */
    public function erfasser_reicht_fuer_anderes_mitglied_ein(): void
    {
        // Erfasser (50) erstellt für Mitglied (10)
        $entry = $this->createEntry('entwurf', 10, 50);

        // Erfasser kann einreichen (als Ersteller)
        $this->assertTrue($this->service->submit($entry, $this->erfasser));
    }

    /** @test */
    public function mitglied_kann_eigenen_fremderstellten_antrag_zurueckziehen(): void
    {
        // Erfasser hat für Mitglied erstellt, Mitglied zieht zurück
        $entry = $this->createEntry('eingereicht', 10, 50);

        $this->assertTrue($this->service->withdraw($entry, $this->mitglied));
    }

    // =========================================================================
    // Szenario 9: Berechtigungsgrenzen
    // =========================================================================

    /** @test */
    public function mitglied_kann_nicht_genehmigen(): void
    {
        $entry = $this->createEntry('eingereicht', 20, 20);

        $this->expectException(AuthorizationException::class);

        $this->service->approve($entry, $this->mitglied);
    }

    /** @test */
    public function mitglied_kann_nicht_korrigieren(): void
    {
        $entry = $this->createEntry('freigegeben');

        $this->expectException(AuthorizationException::class);

        $this->service->correct($entry, $this->mitglied, 3.0, 'Grund');
    }

    /** @test */
    public function fremder_user_kann_nicht_einreichen(): void
    {
        $entry = $this->createEntry('entwurf', 10, 10);
        $fremder = $this->createTestUser(99, ['mitglied']);

        $this->expectException(AuthorizationException::class);

        $this->service->submit($entry, $fremder);
    }
}
