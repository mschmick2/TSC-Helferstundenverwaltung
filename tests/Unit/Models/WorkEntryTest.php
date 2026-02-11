<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\WorkEntry;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für das WorkEntry-Model
 */
class WorkEntryTest extends TestCase
{
    // =========================================================================
    // fromArray() - Konstruktion
    // =========================================================================

    /** @test */
    public function from_array_setzt_alle_felder_korrekt(): void
    {
        $data = [
            'id' => '1',
            'entry_number' => '2025-00001',
            'user_id' => '10',
            'created_by_user_id' => '20',
            'category_id' => '3',
            'work_date' => '2025-02-01',
            'time_from' => '08:00',
            'time_to' => '12:00',
            'hours' => '4.5',
            'project' => 'Vereinsfest',
            'description' => 'Aufbau Zelte',
            'status' => 'eingereicht',
            'reviewed_by_user_id' => '30',
            'reviewed_at' => '2025-02-05 10:00:00',
            'rejection_reason' => null,
            'return_reason' => null,
            'is_corrected' => '0',
            'corrected_by_user_id' => null,
            'corrected_at' => null,
            'correction_reason' => null,
            'original_hours' => null,
            'submitted_at' => '2025-02-01 14:00:00',
            'created_at' => '2025-02-01 13:00:00',
            'updated_at' => '2025-02-05 10:00:00',
            'deleted_at' => null,
            'version' => '2',
            'user_name' => 'Max Mustermann',
            'created_by_name' => 'Erfasser Eins',
            'category_name' => 'Veranstaltungen',
            'reviewed_by_name' => 'Prüfer Eins',
            'open_questions_count' => '1',
        ];

        $entry = WorkEntry::fromArray($data);

        $this->assertSame(1, $entry->getId());
        $this->assertSame('2025-00001', $entry->getEntryNumber());
        $this->assertSame(10, $entry->getUserId());
        $this->assertSame(20, $entry->getCreatedByUserId());
        $this->assertSame(3, $entry->getCategoryId());
        $this->assertSame('2025-02-01', $entry->getWorkDate());
        $this->assertSame('08:00', $entry->getTimeFrom());
        $this->assertSame('12:00', $entry->getTimeTo());
        $this->assertSame(4.5, $entry->getHours());
        $this->assertSame('Vereinsfest', $entry->getProject());
        $this->assertSame('Aufbau Zelte', $entry->getDescription());
        $this->assertSame('eingereicht', $entry->getStatus());
        $this->assertSame(30, $entry->getReviewedByUserId());
        $this->assertFalse($entry->isCorrected());
        $this->assertSame(2, $entry->getVersion());
        $this->assertSame('Max Mustermann', $entry->getUserName());
        $this->assertSame('Erfasser Eins', $entry->getCreatedByName());
        $this->assertSame('Veranstaltungen', $entry->getCategoryName());
        $this->assertSame('Prüfer Eins', $entry->getReviewedByName());
        $this->assertSame(1, $entry->getOpenQuestionsCount());
        $this->assertSame('2025-02-05 10:00:00', $entry->getUpdatedAt());
    }

    /** @test */
    public function from_array_mit_leeren_daten_setzt_defaults(): void
    {
        $entry = WorkEntry::fromArray([]);

        $this->assertNull($entry->getId());
        $this->assertSame('', $entry->getEntryNumber());
        $this->assertSame(0, $entry->getUserId());
        $this->assertNull($entry->getCategoryId());
        $this->assertSame(0.0, $entry->getHours());
        $this->assertSame('entwurf', $entry->getStatus());
        $this->assertSame(1, $entry->getVersion());
        $this->assertNull($entry->getReviewedByUserId());
        $this->assertNull($entry->getProject());
        $this->assertSame(0, $entry->getOpenQuestionsCount());
    }

    /** @test */
    public function from_array_korrektur_felder(): void
    {
        $entry = WorkEntry::fromArray([
            'is_corrected' => '1',
            'corrected_by_user_id' => '5',
            'corrected_at' => '2025-02-09 10:00:00',
            'correction_reason' => 'Falsche Stundenzahl',
            'original_hours' => '6.0',
        ]);

        $this->assertTrue($entry->isCorrected());
        $this->assertSame(5, $entry->getCorrectedByUserId());
        $this->assertSame('2025-02-09 10:00:00', $entry->getCorrectedAt());
        $this->assertSame('Falsche Stundenzahl', $entry->getCorrectionReason());
        $this->assertSame(6.0, $entry->getOriginalHours());
    }

    // =========================================================================
    // Status-Übergänge (TRANSITIONS)
    // =========================================================================

    /**
     * @test
     * @dataProvider gueltige_uebergaenge_provider
     */
    public function erlaubte_uebergaenge(string $vonStatus, string $nachStatus): void
    {
        $entry = WorkEntry::fromArray(['status' => $vonStatus]);

        $this->assertTrue($entry->canTransitionTo($nachStatus));
    }

    public static function gueltige_uebergaenge_provider(): array
    {
        return [
            'entwurf → eingereicht' => ['entwurf', 'eingereicht'],
            'eingereicht → in_klaerung' => ['eingereicht', 'in_klaerung'],
            'eingereicht → freigegeben' => ['eingereicht', 'freigegeben'],
            'eingereicht → abgelehnt' => ['eingereicht', 'abgelehnt'],
            'eingereicht → entwurf' => ['eingereicht', 'entwurf'],
            'eingereicht → storniert' => ['eingereicht', 'storniert'],
            'in_klaerung → freigegeben' => ['in_klaerung', 'freigegeben'],
            'in_klaerung → abgelehnt' => ['in_klaerung', 'abgelehnt'],
            'in_klaerung → entwurf' => ['in_klaerung', 'entwurf'],
            'in_klaerung → storniert' => ['in_klaerung', 'storniert'],
            'storniert → entwurf' => ['storniert', 'entwurf'],
        ];
    }

    /**
     * @test
     * @dataProvider ungueltige_uebergaenge_provider
     */
    public function verbotene_uebergaenge(string $vonStatus, string $nachStatus): void
    {
        $entry = WorkEntry::fromArray(['status' => $vonStatus]);

        $this->assertFalse($entry->canTransitionTo($nachStatus));
    }

    public static function ungueltige_uebergaenge_provider(): array
    {
        return [
            'entwurf → freigegeben' => ['entwurf', 'freigegeben'],
            'entwurf → abgelehnt' => ['entwurf', 'abgelehnt'],
            'entwurf → in_klaerung' => ['entwurf', 'in_klaerung'],
            'entwurf → storniert' => ['entwurf', 'storniert'],
            'freigegeben → entwurf' => ['freigegeben', 'entwurf'],
            'freigegeben → eingereicht' => ['freigegeben', 'eingereicht'],
            'freigegeben → abgelehnt' => ['freigegeben', 'abgelehnt'],
            'freigegeben → storniert' => ['freigegeben', 'storniert'],
            'abgelehnt → entwurf' => ['abgelehnt', 'entwurf'],
            'abgelehnt → eingereicht' => ['abgelehnt', 'eingereicht'],
            'abgelehnt → freigegeben' => ['abgelehnt', 'freigegeben'],
            'storniert → eingereicht' => ['storniert', 'eingereicht'],
            'storniert → freigegeben' => ['storniert', 'freigegeben'],
        ];
    }

    // =========================================================================
    // Status-Anzeige
    // =========================================================================

    /** @test */
    public function status_label_fuer_alle_status(): void
    {
        $expected = [
            'entwurf' => 'Entwurf',
            'eingereicht' => 'Eingereicht',
            'in_klaerung' => 'In Klärung',
            'freigegeben' => 'Freigegeben',
            'abgelehnt' => 'Abgelehnt',
            'storniert' => 'Storniert',
        ];

        foreach ($expected as $status => $label) {
            $entry = WorkEntry::fromArray(['status' => $status]);
            $this->assertSame($label, $entry->getStatusLabel(), "Falsches Label für Status '{$status}'");
        }
    }

    /** @test */
    public function status_badge_fuer_alle_status(): void
    {
        $entry = WorkEntry::fromArray(['status' => 'freigegeben']);
        $this->assertSame('bg-success', $entry->getStatusBadge());

        $entry = WorkEntry::fromArray(['status' => 'abgelehnt']);
        $this->assertSame('bg-danger', $entry->getStatusBadge());
    }

    /** @test */
    public function unbekannter_status_hat_fallback(): void
    {
        $entry = WorkEntry::fromArray(['status' => 'unbekannt']);

        $this->assertSame('unbekannt', $entry->getStatusLabel());
        $this->assertSame('bg-secondary', $entry->getStatusBadge());
    }

    // =========================================================================
    // Zustandsprüfungen
    // =========================================================================

    /** @test */
    public function is_self_entry_bei_gleichem_user_und_creator(): void
    {
        $entry = WorkEntry::fromArray(['user_id' => '5', 'created_by_user_id' => '5']);

        $this->assertTrue($entry->isSelfEntry());
    }

    /** @test */
    public function is_self_entry_false_bei_fremderstellung(): void
    {
        $entry = WorkEntry::fromArray(['user_id' => '5', 'created_by_user_id' => '10']);

        $this->assertFalse($entry->isSelfEntry());
    }

    /** @test */
    public function is_editable_nur_im_entwurf(): void
    {
        $this->assertTrue(WorkEntry::fromArray(['status' => 'entwurf'])->isEditable());
        $this->assertFalse(WorkEntry::fromArray(['status' => 'eingereicht'])->isEditable());
        $this->assertFalse(WorkEntry::fromArray(['status' => 'freigegeben'])->isEditable());
        $this->assertFalse(WorkEntry::fromArray(['status' => 'abgelehnt'])->isEditable());
    }

    /** @test */
    public function is_submittable_nur_im_entwurf(): void
    {
        $this->assertTrue(WorkEntry::fromArray(['status' => 'entwurf'])->isSubmittable());
        $this->assertFalse(WorkEntry::fromArray(['status' => 'eingereicht'])->isSubmittable());
        $this->assertFalse(WorkEntry::fromArray(['status' => 'freigegeben'])->isSubmittable());
    }

    /** @test */
    public function is_withdrawable_bei_eingereicht_und_in_klaerung(): void
    {
        $this->assertTrue(WorkEntry::fromArray(['status' => 'eingereicht'])->isWithdrawable());
        $this->assertTrue(WorkEntry::fromArray(['status' => 'in_klaerung'])->isWithdrawable());
        $this->assertFalse(WorkEntry::fromArray(['status' => 'entwurf'])->isWithdrawable());
        $this->assertFalse(WorkEntry::fromArray(['status' => 'freigegeben'])->isWithdrawable());
        $this->assertFalse(WorkEntry::fromArray(['status' => 'storniert'])->isWithdrawable());
    }

    /** @test */
    public function is_reactivatable_nur_bei_storniert(): void
    {
        $this->assertTrue(WorkEntry::fromArray(['status' => 'storniert'])->isReactivatable());
        $this->assertFalse(WorkEntry::fromArray(['status' => 'entwurf'])->isReactivatable());
        $this->assertFalse(WorkEntry::fromArray(['status' => 'abgelehnt'])->isReactivatable());
    }

    // =========================================================================
    // Setter
    // =========================================================================

    /** @test */
    public function setter_fuer_status(): void
    {
        $entry = WorkEntry::fromArray(['status' => 'entwurf']);
        $entry->setStatus('eingereicht');

        $this->assertSame('eingereicht', $entry->getStatus());
    }

    /** @test */
    public function setter_fuer_review_felder(): void
    {
        $entry = WorkEntry::fromArray([]);

        $entry->setReviewedByUserId(42);
        $this->assertSame(42, $entry->getReviewedByUserId());

        $entry->setReviewedAt('2025-02-09 12:00:00');
        $this->assertSame('2025-02-09 12:00:00', $entry->getReviewedAt());

        $entry->setRejectionReason('Unvollständig');
        $this->assertSame('Unvollständig', $entry->getRejectionReason());

        $entry->setReturnReason('Bitte ergänzen');
        $this->assertSame('Bitte ergänzen', $entry->getReturnReason());

        $entry->setSubmittedAt('2025-02-01 08:00:00');
        $this->assertSame('2025-02-01 08:00:00', $entry->getSubmittedAt());
    }

    /** @test */
    public function setter_nullable_felder(): void
    {
        $entry = WorkEntry::fromArray([
            'reviewed_by_user_id' => '5',
            'rejection_reason' => 'Test',
        ]);

        $entry->setReviewedByUserId(null);
        $this->assertNull($entry->getReviewedByUserId());

        $entry->setRejectionReason(null);
        $this->assertNull($entry->getRejectionReason());
    }
}
