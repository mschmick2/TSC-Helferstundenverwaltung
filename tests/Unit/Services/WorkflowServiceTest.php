<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

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
 * Unit-Tests für WorkflowService
 *
 * Testet Geschäftsregeln: Statusübergänge, Selbstgenehmigung, Berechtigungen
 */
class WorkflowServiceTest extends TestCase
{
    private WorkflowService $service;
    private WorkEntryRepository&MockObject $entryRepo;
    private DialogRepository&MockObject $dialogRepo;
    private AuditService&MockObject $auditService;
    private EmailService&MockObject $emailService;
    private UserRepository&MockObject $userRepo;
    private LoggerInterface&MockObject $logger;

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
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function createUser(int $id, array $roles = ['mitglied']): User
    {
        $user = User::fromArray(['id' => (string) $id]);
        $user->setRoles($roles);
        return $user;
    }

    private function createEntry(
        int $id = 1,
        string $status = 'entwurf',
        int $userId = 10,
        int $createdByUserId = 10,
        int $version = 1
    ): WorkEntry {
        return WorkEntry::fromArray([
            'id' => (string) $id,
            'entry_number' => '2025-00001',
            'status' => $status,
            'user_id' => (string) $userId,
            'created_by_user_id' => (string) $createdByUserId,
            'hours' => '4.5',
            'version' => (string) $version,
        ]);
    }

    // =========================================================================
    // submit()
    // =========================================================================

    /** @test */
    public function submit_von_entwurf_erfolgreich(): void
    {
        $entry = $this->createEntry(1, 'entwurf', 10);
        $user = $this->createUser(10);

        $this->entryRepo->expects($this->once())
            ->method('updateStatus')
            ->with(1, 'eingereicht', 1, $this->anything())
            ->willReturn(true);

        $this->userRepo->method('findByRole')->willReturn([]);

        $result = $this->service->submit($entry, $user);

        $this->assertTrue($result);
    }

    /** @test */
    public function submit_von_freigegeben_wirft_exception(): void
    {
        $entry = $this->createEntry(1, 'freigegeben', 10);
        $user = $this->createUser(10);

        $this->expectException(BusinessRuleException::class);

        $this->service->submit($entry, $user);
    }

    /** @test */
    public function submit_von_fremdem_benutzer_wirft_exception(): void
    {
        $entry = $this->createEntry(1, 'entwurf', 10, 10);
        $user = $this->createUser(99); // Anderer User

        $this->expectException(AuthorizationException::class);

        $this->service->submit($entry, $user);
    }

    /** @test */
    public function submit_bei_optimistic_locking_konflikt(): void
    {
        $entry = $this->createEntry(1, 'entwurf', 10);
        $user = $this->createUser(10);

        $this->entryRepo->method('updateStatus')->willReturn(false);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('zwischenzeitlich geändert');

        $this->service->submit($entry, $user);
    }

    /** @test */
    public function submit_benachrichtigt_pruefer(): void
    {
        $entry = $this->createEntry(1, 'entwurf', 10);
        $user = $this->createUser(10);

        $this->entryRepo->method('updateStatus')->willReturn(true);

        $pruefer = $this->createUser(20, ['pruefer']);
        $this->userRepo->method('findByRole')
            ->with('pruefer')
            ->willReturn([$pruefer]);

        $owner = $this->createUser(10);
        $this->userRepo->method('findById')
            ->with(10)
            ->willReturn($owner);

        $this->emailService->expects($this->once())
            ->method('sendEntrySubmitted');

        $this->service->submit($entry, $user);
    }

    // =========================================================================
    // approve() - Selbstgenehmigung (KRITISCH)
    // =========================================================================

    /** @test */
    public function approve_erfolgreich_durch_fremden_pruefer(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $reviewer = $this->createUser(20, ['pruefer']);

        $this->entryRepo->method('updateStatus')->willReturn(true);
        $this->userRepo->method('findById')->willReturn($this->createUser(10));

        $result = $this->service->approve($entry, $reviewer);

        $this->assertTrue($result);
    }

    /** @test */
    public function approve_eigenen_antrag_verhindert(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $reviewer = $this->createUser(10, ['pruefer']); // Gleicher User!

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Eigene Anträge');

        $this->service->approve($entry, $reviewer);
    }

    /** @test */
    public function approve_als_ersteller_eines_fremdeintrags_verhindert(): void
    {
        // Erfasser (User 20) hat Eintrag für User 10 erstellt
        $entry = $this->createEntry(1, 'eingereicht', 10, 20);
        $reviewer = $this->createUser(20, ['pruefer']); // Ersteller = Prüfer

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Von Ihnen erstellte');

        $this->service->approve($entry, $reviewer);
    }

    /** @test */
    public function approve_admin_eigenen_antrag_verhindert(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $admin = $this->createUser(10, ['administrator']); // Auch Admin darf nicht!

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Eigene Anträge');

        $this->service->approve($entry, $admin);
    }

    /** @test */
    public function approve_ohne_pruefer_rolle_wirft_exception(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $user = $this->createUser(20, ['mitglied']); // Kein Prüfer!

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Keine Berechtigung');

        $this->service->approve($entry, $user);
    }

    /** @test */
    public function approve_von_entwurf_nicht_moeglich(): void
    {
        $entry = $this->createEntry(1, 'entwurf', 10, 10);
        $reviewer = $this->createUser(20, ['pruefer']);

        $this->expectException(BusinessRuleException::class);

        $this->service->approve($entry, $reviewer);
    }

    /** @test */
    public function approve_von_in_klaerung_moeglich(): void
    {
        $entry = $this->createEntry(1, 'in_klaerung', 10, 10);
        $reviewer = $this->createUser(20, ['pruefer']);

        $this->entryRepo->method('updateStatus')->willReturn(true);
        $this->userRepo->method('findById')->willReturn($this->createUser(10));

        $result = $this->service->approve($entry, $reviewer);

        $this->assertTrue($result);
    }

    // =========================================================================
    // reject()
    // =========================================================================

    /** @test */
    public function reject_mit_begruendung_erfolgreich(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $reviewer = $this->createUser(20, ['pruefer']);

        $this->entryRepo->method('updateStatus')->willReturn(true);
        $this->userRepo->method('findById')->willReturn($this->createUser(10));

        $result = $this->service->reject($entry, $reviewer, 'Unvollständige Angaben');

        $this->assertTrue($result);
    }

    /** @test */
    public function reject_ohne_begruendung_wirft_exception(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $reviewer = $this->createUser(20, ['pruefer']);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Begründung');

        $this->service->reject($entry, $reviewer, '');
    }

    /** @test */
    public function reject_nur_whitespace_begruendung_wirft_exception(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $reviewer = $this->createUser(20, ['pruefer']);

        $this->expectException(BusinessRuleException::class);

        $this->service->reject($entry, $reviewer, '   ');
    }

    /** @test */
    public function reject_eigenen_antrag_verhindert(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $reviewer = $this->createUser(10, ['pruefer']);

        $this->expectException(BusinessRuleException::class);

        $this->service->reject($entry, $reviewer, 'Grund');
    }

    // =========================================================================
    // returnForRevision()
    // =========================================================================

    /** @test */
    public function return_for_revision_erstellt_dialog_nachricht(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $reviewer = $this->createUser(20, ['pruefer']);

        $this->entryRepo->method('updateStatus')->willReturn(true);
        $this->userRepo->method('findById')->willReturn($this->createUser(10));

        $this->dialogRepo->expects($this->once())
            ->method('create')
            ->with(1, 20, 'Bitte nachliefern', true);

        $this->service->returnForRevision($entry, $reviewer, 'Bitte nachliefern');
    }

    /** @test */
    public function return_for_revision_ohne_begruendung_wirft_exception(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $reviewer = $this->createUser(20, ['pruefer']);

        $this->expectException(BusinessRuleException::class);

        $this->service->returnForRevision($entry, $reviewer, '');
    }

    // =========================================================================
    // withdraw()
    // =========================================================================

    /** @test */
    public function withdraw_von_eingereicht_erfolgreich(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $user = $this->createUser(10);

        $this->entryRepo->method('updateStatus')->willReturn(true);

        $result = $this->service->withdraw($entry, $user);

        $this->assertTrue($result);
    }

    /** @test */
    public function withdraw_fremder_antrag_nicht_moeglich(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $user = $this->createUser(99);

        $this->expectException(AuthorizationException::class);

        $this->service->withdraw($entry, $user);
    }

    // =========================================================================
    // cancel()
    // =========================================================================

    /** @test */
    public function cancel_von_eingereicht_erfolgreich(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $user = $this->createUser(10);

        $this->entryRepo->method('updateStatus')->willReturn(true);

        $result = $this->service->cancel($entry, $user);

        $this->assertTrue($result);
    }

    /** @test */
    public function cancel_von_entwurf_nicht_moeglich(): void
    {
        $entry = $this->createEntry(1, 'entwurf', 10, 10);
        $user = $this->createUser(10);

        $this->expectException(BusinessRuleException::class);

        $this->service->cancel($entry, $user);
    }

    // =========================================================================
    // reactivate()
    // =========================================================================

    /** @test */
    public function reactivate_von_storniert_erfolgreich(): void
    {
        $entry = $this->createEntry(1, 'storniert', 10, 10);
        $user = $this->createUser(10);

        $this->entryRepo->method('updateStatus')->willReturn(true);

        $result = $this->service->reactivate($entry, $user);

        $this->assertTrue($result);
    }

    /** @test */
    public function reactivate_von_abgelehnt_nicht_moeglich(): void
    {
        $entry = $this->createEntry(1, 'abgelehnt', 10, 10);
        $user = $this->createUser(10);

        $this->expectException(BusinessRuleException::class);

        $this->service->reactivate($entry, $user);
    }

    // =========================================================================
    // correct()
    // =========================================================================

    /** @test */
    public function correct_freigegebenen_antrag_erfolgreich(): void
    {
        $entry = $this->createEntry(1, 'freigegeben', 10, 10);
        $corrector = $this->createUser(20, ['pruefer']);

        $this->entryRepo->method('correctEntry')->willReturn(true);
        $this->userRepo->method('findById')->willReturn($this->createUser(10));

        $result = $this->service->correct($entry, $corrector, 3.0, 'Falsche Stundenzahl');

        $this->assertTrue($result);
    }

    /** @test */
    public function correct_nicht_freigegebenen_antrag_wirft_exception(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $corrector = $this->createUser(20, ['pruefer']);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('freigegebene');

        $this->service->correct($entry, $corrector, 3.0, 'Grund');
    }

    /** @test */
    public function correct_eigenen_antrag_verhindert(): void
    {
        $entry = $this->createEntry(1, 'freigegeben', 10, 10);
        $corrector = $this->createUser(10, ['pruefer']);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('selbst korrigiert');

        $this->service->correct($entry, $corrector, 3.0, 'Grund');
    }

    /** @test */
    public function correct_ohne_berechtigung_wirft_exception(): void
    {
        $entry = $this->createEntry(1, 'freigegeben', 10, 10);
        $mitglied = $this->createUser(20, ['mitglied']);

        $this->expectException(AuthorizationException::class);

        $this->service->correct($entry, $mitglied, 3.0, 'Grund');
    }

    /** @test */
    public function correct_ohne_begruendung_wirft_exception(): void
    {
        $entry = $this->createEntry(1, 'freigegeben', 10, 10);
        $corrector = $this->createUser(20, ['administrator']);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Begründung');

        $this->service->correct($entry, $corrector, 3.0, '');
    }

    /** @test */
    public function correct_als_admin_erfolgreich(): void
    {
        $entry = $this->createEntry(1, 'freigegeben', 10, 10);
        $admin = $this->createUser(20, ['administrator']);

        $this->entryRepo->method('correctEntry')->willReturn(true);
        $this->userRepo->method('findById')->willReturn($this->createUser(10));

        $result = $this->service->correct($entry, $admin, 2.0, 'Admin-Korrektur');

        $this->assertTrue($result);
    }

    // =========================================================================
    // E-Mail-Fehler blockieren Workflow nicht
    // =========================================================================

    /** @test */
    public function email_fehler_blockiert_approve_nicht(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $reviewer = $this->createUser(20, ['pruefer']);

        $this->entryRepo->method('updateStatus')->willReturn(true);
        $this->userRepo->method('findById')->willReturn($this->createUser(10));

        $this->emailService->method('sendEntryApproved')
            ->willThrowException(new \RuntimeException('SMTP error'));

        // Sollte trotzdem erfolgreich sein
        $result = $this->service->approve($entry, $reviewer);

        $this->assertTrue($result);
    }

    /** @test */
    public function email_fehler_wird_geloggt(): void
    {
        $entry = $this->createEntry(1, 'eingereicht', 10, 10);
        $reviewer = $this->createUser(20, ['pruefer']);

        $this->entryRepo->method('updateStatus')->willReturn(true);
        $this->userRepo->method('findById')->willReturn($this->createUser(10));

        $this->emailService->method('sendEntryApproved')
            ->willThrowException(new \RuntimeException('SMTP error'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('fehlgeschlagen'));

        $this->service->approve($entry, $reviewer);
    }
}
