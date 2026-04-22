<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Models\WorkEntry;
use App\Repositories\DialogRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkEntryRepository;
use DateInterval;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Service für Workflow-Statusübergänge
 *
 * Implementiert alle Geschäftsregeln gem. REQ-WF:
 * - Erlaubte Statusübergänge
 * - Selbstgenehmigung verhindern
 * - Optimistic Locking
 * - Audit-Trail bei jeder Statusänderung
 * - E-Mail-Benachrichtigungen bei Statusänderungen (Abschnitt 9)
 */
class WorkflowService
{
    public function __construct(
        private WorkEntryRepository $entryRepo,
        private DialogRepository $dialogRepo,
        private AuditService $auditService,
        private EmailService $emailService,
        private UserRepository $userRepo,
        private LoggerInterface $logger,
        private string $baseUrl,
        private ?SchedulerService $scheduler = null,
        private ?SettingsService $settings = null
    ) {
    }

    /**
     * Eintrag einreichen (entwurf → eingereicht)
     */
    public function submit(WorkEntry $entry, User $user): bool
    {
        $this->assertTransition($entry, 'eingereicht');
        $this->assertOwnerOrCreator($entry, $user);

        $result = $this->entryRepo->updateStatus(
            $entry->getId(),
            'eingereicht',
            $entry->getVersion(),
            ['submitted_at' => date('Y-m-d H:i:s')]
        );

        if (!$result) {
            throw new BusinessRuleException('Der Eintrag wurde zwischenzeitlich geändert. Bitte laden Sie die Seite neu.');
        }

        $this->auditService->log(
            'status_change',
            'work_entries',
            $entry->getId(),
            ['status' => $entry->getStatus()],
            ['status' => 'eingereicht'],
            'Antrag eingereicht',
            $entry->getEntryNumber()
        );

        // E-Mail an alle Prüfer
        $this->notifyPruefer($entry, $user);

        return true;
    }

    /**
     * Eintrag zurückziehen → entwurf (eingereicht/in_klaerung → entwurf)
     */
    public function withdraw(WorkEntry $entry, User $user): bool
    {
        $this->assertTransition($entry, 'entwurf');
        $this->assertOwnerOrCreator($entry, $user);

        $result = $this->entryRepo->updateStatus(
            $entry->getId(),
            'entwurf',
            $entry->getVersion()
        );

        if (!$result) {
            throw new BusinessRuleException('Der Eintrag wurde zwischenzeitlich geändert. Bitte laden Sie die Seite neu.');
        }

        $this->auditService->log(
            'status_change',
            'work_entries',
            $entry->getId(),
            ['status' => $entry->getStatus()],
            ['status' => 'entwurf'],
            'Antrag zurückgezogen',
            $entry->getEntryNumber()
        );

        $this->cancelDialogReminder($entry->getId());

        return true;
    }

    /**
     * Eintrag stornieren (eingereicht/in_klaerung → storniert)
     */
    public function cancel(WorkEntry $entry, User $user): bool
    {
        $this->assertTransition($entry, 'storniert');
        $this->assertOwnerOrCreator($entry, $user);

        $result = $this->entryRepo->updateStatus(
            $entry->getId(),
            'storniert',
            $entry->getVersion()
        );

        if (!$result) {
            throw new BusinessRuleException('Der Eintrag wurde zwischenzeitlich geändert. Bitte laden Sie die Seite neu.');
        }

        $this->auditService->log(
            'status_change',
            'work_entries',
            $entry->getId(),
            ['status' => $entry->getStatus()],
            ['status' => 'storniert'],
            'Antrag storniert',
            $entry->getEntryNumber()
        );

        $this->cancelDialogReminder($entry->getId());

        return true;
    }

    /**
     * Stornierten Eintrag reaktivieren (storniert → entwurf)
     */
    public function reactivate(WorkEntry $entry, User $user): bool
    {
        $this->assertTransition($entry, 'entwurf');
        $this->assertOwnerOrCreator($entry, $user);

        $result = $this->entryRepo->updateStatus(
            $entry->getId(),
            'entwurf',
            $entry->getVersion()
        );

        if (!$result) {
            throw new BusinessRuleException('Der Eintrag wurde zwischenzeitlich geändert. Bitte laden Sie die Seite neu.');
        }

        $this->auditService->log(
            'status_change',
            'work_entries',
            $entry->getId(),
            ['status' => $entry->getStatus()],
            ['status' => 'entwurf'],
            'Stornierter Antrag reaktiviert',
            $entry->getEntryNumber()
        );

        return true;
    }

    /**
     * Eintrag freigeben (eingereicht/in_klaerung → freigegeben)
     */
    public function approve(WorkEntry $entry, User $reviewer): bool
    {
        $this->assertTransition($entry, 'freigegeben');
        $this->assertReviewPermission($entry, $reviewer);

        $now = date('Y-m-d H:i:s');

        $result = $this->entryRepo->updateStatus(
            $entry->getId(),
            'freigegeben',
            $entry->getVersion(),
            [
                'reviewed_by_user_id' => $reviewer->getId(),
                'reviewed_at' => $now,
            ]
        );

        if (!$result) {
            throw new BusinessRuleException('Der Eintrag wurde zwischenzeitlich geändert. Bitte laden Sie die Seite neu.');
        }

        $this->auditService->log(
            'status_change',
            'work_entries',
            $entry->getId(),
            ['status' => $entry->getStatus()],
            ['status' => 'freigegeben', 'reviewed_by_user_id' => $reviewer->getId()],
            'Antrag freigegeben',
            $entry->getEntryNumber()
        );

        // E-Mail an Antragsteller
        $this->notifyOwner($entry, function (string $email, string $vorname) use ($entry) {
            $this->emailService->sendEntryApproved(
                $email,
                $vorname,
                $entry->getEntryNumber(),
                $this->getEntryUrl($entry)
            );
        });

        $this->cancelDialogReminder($entry->getId());

        return true;
    }

    /**
     * Eintrag ablehnen (eingereicht/in_klaerung → abgelehnt)
     */
    public function reject(WorkEntry $entry, User $reviewer, string $reason): bool
    {
        $this->assertTransition($entry, 'abgelehnt');
        $this->assertReviewPermission($entry, $reviewer);

        if (trim($reason) === '') {
            throw new BusinessRuleException('Bei einer Ablehnung muss eine Begründung angegeben werden.');
        }

        $now = date('Y-m-d H:i:s');

        $result = $this->entryRepo->updateStatus(
            $entry->getId(),
            'abgelehnt',
            $entry->getVersion(),
            [
                'reviewed_by_user_id' => $reviewer->getId(),
                'reviewed_at' => $now,
                'rejection_reason' => $reason,
            ]
        );

        if (!$result) {
            throw new BusinessRuleException('Der Eintrag wurde zwischenzeitlich geändert. Bitte laden Sie die Seite neu.');
        }

        $this->auditService->log(
            'status_change',
            'work_entries',
            $entry->getId(),
            ['status' => $entry->getStatus()],
            ['status' => 'abgelehnt', 'rejection_reason' => $reason],
            'Antrag abgelehnt',
            $entry->getEntryNumber()
        );

        // E-Mail an Antragsteller
        $this->notifyOwner($entry, function (string $email, string $vorname) use ($entry, $reason) {
            $this->emailService->sendEntryRejected(
                $email,
                $vorname,
                $entry->getEntryNumber(),
                $reason,
                $this->getEntryUrl($entry)
            );
        });

        $this->cancelDialogReminder($entry->getId());

        return true;
    }

    /**
     * Eintrag zur Klärung zurückgeben (eingereicht → in_klaerung)
     */
    public function returnForRevision(WorkEntry $entry, User $reviewer, string $reason): bool
    {
        $this->assertTransition($entry, 'in_klaerung');
        $this->assertReviewPermission($entry, $reviewer);

        if (trim($reason) === '') {
            throw new BusinessRuleException('Bei einer Rückfrage muss eine Begründung angegeben werden.');
        }

        $result = $this->entryRepo->updateStatus(
            $entry->getId(),
            'in_klaerung',
            $entry->getVersion(),
            ['return_reason' => $reason]
        );

        if (!$result) {
            throw new BusinessRuleException('Der Eintrag wurde zwischenzeitlich geändert. Bitte laden Sie die Seite neu.');
        }

        // Rückfrage als Dialog-Nachricht speichern
        $this->dialogRepo->create($entry->getId(), $reviewer->getId(), $reason, true);

        $this->auditService->log(
            'status_change',
            'work_entries',
            $entry->getId(),
            ['status' => $entry->getStatus()],
            ['status' => 'in_klaerung', 'return_reason' => $reason],
            'Antrag zur Klärung zurückgegeben',
            $entry->getEntryNumber()
        );

        // E-Mail an Antragsteller
        $this->notifyOwner($entry, function (string $email, string $vorname) use ($entry, $reason) {
            $this->emailService->sendEntryReturnedForRevision(
                $email,
                $vorname,
                $entry->getEntryNumber(),
                $reason,
                $this->getEntryUrl($entry)
            );
        });

        // Mitglied erinnern, falls keine Antwort kommt. Intervall aus
        // Settings (reminder_days, Default 3 Tage) — Admin stellt ein.
        $reminderDays = $this->settings !== null
            ? max(1, $this->settings->getInt('reminder_days', 3))
            : 3;
        $this->dispatchDialogReminder($entry->getId(), $reminderDays);

        return true;
    }

    /**
     * Eintrag zur Überarbeitung zurück an Mitglied (eingereicht/in_klaerung → entwurf)
     *
     * Anders als returnForRevision(): Der Pruefer ist mit dem Antrag grundsaetzlich
     * nicht einverstanden und erwartet eine inhaltliche Ueberarbeitung durch das
     * Mitglied. Der Antrag verlaesst die Prueferliste und landet wieder im
     * Entwurfszustand, wo das Mitglied ihn editieren und neu einreichen kann.
     * Der Dialog-Verlauf bleibt vollstaendig erhalten.
     */
    public function returnToDraft(WorkEntry $entry, User $reviewer, string $reason): bool
    {
        $this->assertTransition($entry, 'entwurf');
        $this->assertReviewPermission($entry, $reviewer);

        if (trim($reason) === '') {
            throw new BusinessRuleException('Bei der Rücksetzung zur Überarbeitung muss eine Begründung angegeben werden.');
        }

        $oldStatus = $entry->getStatus();

        $result = $this->entryRepo->updateStatus(
            $entry->getId(),
            'entwurf',
            $entry->getVersion(),
            ['return_reason' => $reason]
        );

        if (!$result) {
            throw new BusinessRuleException('Der Eintrag wurde zwischenzeitlich geändert. Bitte laden Sie die Seite neu.');
        }

        // Begruendung als Dialog-Nachricht sichern, damit der Verlauf nachvollziehbar bleibt
        $this->dialogRepo->create($entry->getId(), $reviewer->getId(), $reason, true);

        $this->auditService->log(
            'status_change',
            'work_entries',
            $entry->getId(),
            ['status' => $oldStatus],
            ['status' => 'entwurf', 'return_reason' => $reason],
            'Antrag zur Überarbeitung zurück an Mitglied',
            $entry->getEntryNumber()
        );

        // E-Mail an Antragsteller
        $this->notifyOwner($entry, function (string $email, string $vorname) use ($entry, $reason) {
            $this->emailService->sendEntryReturnedToDraft(
                $email,
                $vorname,
                $entry->getEntryNumber(),
                $reason,
                $this->getEntryUrl($entry)
            );
        });

        $this->cancelDialogReminder($entry->getId());

        return true;
    }

    /**
     * Korrektur an einem freigegebenen Eintrag (durch Admin/Prüfer)
     */
    public function correct(
        WorkEntry $entry,
        User $corrector,
        float $newHours,
        string $reason
    ): bool {
        if ($entry->getStatus() !== 'freigegeben') {
            throw new BusinessRuleException('Nur freigegebene Anträge können korrigiert werden.');
        }

        if (!$corrector->hasRole('administrator') && !$corrector->hasRole('pruefer')) {
            throw new AuthorizationException('Nur Administratoren und Prüfer können Korrekturen vornehmen.');
        }

        if ($corrector->getId() === $entry->getUserId()) {
            throw new BusinessRuleException('Eigene Anträge können nicht selbst korrigiert werden.');
        }

        if (trim($reason) === '') {
            throw new BusinessRuleException('Bei einer Korrektur muss eine Begründung angegeben werden.');
        }

        $originalHours = $entry->getHours();

        $result = $this->entryRepo->correctEntry(
            $entry->getId(),
            $newHours,
            $corrector->getId(),
            $reason,
            $originalHours,
            $entry->getVersion()
        );

        if (!$result) {
            throw new BusinessRuleException('Der Eintrag wurde zwischenzeitlich geändert. Bitte laden Sie die Seite neu.');
        }

        $this->auditService->log(
            'update',
            'work_entries',
            $entry->getId(),
            ['hours' => $originalHours, 'is_corrected' => false],
            ['hours' => $newHours, 'is_corrected' => true, 'correction_reason' => $reason],
            'Antrag korrigiert',
            $entry->getEntryNumber()
        );

        // E-Mail an Antragsteller
        $this->notifyOwner($entry, function (string $email, string $vorname) use ($entry, $originalHours, $newHours, $reason) {
            $this->emailService->sendEntryCorrected(
                $email,
                $vorname,
                $entry->getEntryNumber(),
                $originalHours,
                $newHours,
                $reason,
                $this->getEntryUrl($entry)
            );
        });

        return true;
    }

    // =========================================================================
    // Validierungsmethoden
    // =========================================================================

    /**
     * Prüft ob der Statusübergang erlaubt ist
     */
    private function assertTransition(WorkEntry $entry, string $newStatus): void
    {
        if (!$entry->canTransitionTo($newStatus)) {
            throw new BusinessRuleException(
                sprintf(
                    'Statusübergang von "%s" nach "%s" ist nicht erlaubt.',
                    WorkEntry::STATUS_LABELS[$entry->getStatus()] ?? $entry->getStatus(),
                    WorkEntry::STATUS_LABELS[$newStatus] ?? $newStatus
                )
            );
        }
    }

    /**
     * Prüft ob der User Eigentümer oder Ersteller ist
     */
    private function assertOwnerOrCreator(WorkEntry $entry, User $user): void
    {
        if ($entry->getUserId() !== $user->getId() && $entry->getCreatedByUserId() !== $user->getId()) {
            throw new AuthorizationException('Sie haben keine Berechtigung für diesen Antrag.');
        }
    }

    /**
     * Prüft ob der User als Prüfer agieren darf (inkl. Selbstgenehmigung verhindern)
     */
    private function assertReviewPermission(WorkEntry $entry, User $reviewer): void
    {
        if (!$reviewer->hasRole('pruefer') && !$reviewer->hasRole('administrator')) {
            throw new AuthorizationException('Keine Berechtigung zum Prüfen von Anträgen.');
        }

        // REQ-WF-004: Selbstgenehmigung verhindern
        if ($entry->getUserId() === $reviewer->getId()) {
            throw new BusinessRuleException('Eigene Anträge können nicht selbst genehmigt werden.');
        }

        // Auch der Ersteller (Erfasser) darf seinen eigenen Eintrag nicht prüfen
        if ($entry->getCreatedByUserId() === $reviewer->getId()) {
            throw new BusinessRuleException('Von Ihnen erstellte Anträge können nicht von Ihnen selbst geprüft werden.');
        }
    }

    // =========================================================================
    // E-Mail-Hilfsmethoden
    // =========================================================================

    /**
     * Alle Prüfer über neuen Antrag benachrichtigen
     */
    private function notifyPruefer(WorkEntry $entry, User $submitter): void
    {
        try {
            $pruefer = $this->userRepo->findByRole('pruefer');
            $owner = $this->userRepo->findById($entry->getUserId());
            $memberName = $owner ? $owner->getVollname() : 'Unbekannt';
            $entryUrl = $this->getEntryUrl($entry);

            foreach ($pruefer as $p) {
                // Prüfer soll sich nicht selbst benachrichtigen
                if ($p->getId() === $submitter->getId()) {
                    continue;
                }
                try {
                    $this->emailService->sendEntrySubmitted(
                        $p->getEmail(),
                        $p->getVorname(),
                        $entry->getEntryNumber(),
                        $memberName,
                        $entryUrl
                    );
                } catch (\Throwable $e) {
                    $this->logger->warning('E-Mail an Prüfer fehlgeschlagen: ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Prüfer-Benachrichtigung fehlgeschlagen: ' . $e->getMessage());
        }
    }

    /**
     * Antragsteller benachrichtigen (generisch per Callback)
     */
    private function notifyOwner(WorkEntry $entry, callable $sendFn): void
    {
        try {
            $owner = $this->userRepo->findById($entry->getUserId());
            if ($owner !== null) {
                $sendFn($owner->getEmail(), $owner->getVorname());
            }
        } catch (\Throwable $e) {
            $this->logger->warning('E-Mail an Antragsteller fehlgeschlagen: ' . $e->getMessage());
        }
    }

    /**
     * URL zum Eintrag generieren
     */
    private function getEntryUrl(WorkEntry $entry): string
    {
        return rtrim($this->baseUrl, '/') . '/entries/' . $entry->getId();
    }

    /**
     * Plant einen Dialog-Reminder, falls auf eine Rueckfrage nicht reagiert wird.
     * Idempotent ueber unique_key — wiederholtes returnForRevision aktualisiert
     * den bestehenden Job statt einen zweiten anzulegen.
     */
    private function dispatchDialogReminder(int $entryId, int $daysOpen): void
    {
        if ($this->scheduler === null || $entryId <= 0) {
            return;
        }
        $runAt = (new DateTimeImmutable())->add(new DateInterval('P' . $daysOpen . 'D'));
        $this->scheduler->dispatch(
            'dialog_reminder',
            ['work_entry_id' => $entryId, 'days_open' => $daysOpen],
            $runAt,
            "dialog:{$entryId}:reminder"
        );
    }

    /**
     * Storniert einen pending Dialog-Reminder, sobald der Eintrag den
     * Klaerungs-Zustand verlaesst (entwurf/storniert/freigegeben/abgelehnt).
     */
    private function cancelDialogReminder(int $entryId): void
    {
        if ($this->scheduler === null || $entryId <= 0) {
            return;
        }
        $this->scheduler->cancel("dialog:{$entryId}:reminder");
    }
}
