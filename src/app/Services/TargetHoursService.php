<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\YearlyTargetRepository;

/**
 * Service für Soll-Stunden-Verwaltung
 */
class TargetHoursService
{
    public function __construct(
        private YearlyTargetRepository $targetRepo,
        private SettingsService $settingsService,
        private AuditService $auditService
    ) {
    }

    /**
     * Ist die Soll-Stunden-Funktion aktiviert?
     */
    public function isEnabled(): bool
    {
        return $this->settingsService->isTargetHoursEnabled();
    }

    /**
     * Standard-Sollstunden
     */
    public function getDefaultTarget(): int
    {
        return $this->settingsService->getDefaultTargetHours();
    }

    /**
     * Soll/Ist-Vergleich für einen Benutzer
     *
     * @return array{target: float, actual: float, remaining: float, is_exempt: bool, percentage: float, notes: string}
     */
    public function getUserComparison(int $userId, int $year): array
    {
        $target = $this->targetRepo->findByUserAndYear($userId, $year);
        $actual = $this->targetRepo->getActualHours($userId, $year);

        $targetHours = $target !== null ? (float) $target['target_hours'] : (float) $this->getDefaultTarget();
        $isExempt = $target !== null ? (bool) $target['is_exempt'] : false;
        $notes = $target !== null ? ($target['notes'] ?? '') : '';

        $remaining = max(0, $targetHours - $actual);
        $percentage = $targetHours > 0 ? min(100, ($actual / $targetHours) * 100) : 100;

        return [
            'target' => $targetHours,
            'actual' => $actual,
            'remaining' => $remaining,
            'is_exempt' => $isExempt,
            'percentage' => round($percentage, 1),
            'notes' => $notes,
        ];
    }

    /**
     * Übersicht für alle Mitglieder eines Jahres
     *
     * @return array[]
     */
    public function getAllComparisons(int $year): array
    {
        return $this->targetRepo->getComparisonByYear($year, $this->getDefaultTarget());
    }

    /**
     * Individuelles Ziel setzen
     */
    public function setIndividualTarget(
        int $userId,
        int $year,
        float $hours,
        bool $isExempt,
        ?string $notes,
        int $adminUserId
    ): void {
        $oldTarget = $this->targetRepo->findByUserAndYear($userId, $year);

        $this->targetRepo->upsert($userId, $year, $hours, $isExempt, $notes);

        $this->auditService->log(
            'update',
            'yearly_targets',
            $userId,
            oldValues: $oldTarget,
            newValues: [
                'user_id' => $userId,
                'year' => $year,
                'target_hours' => $hours,
                'is_exempt' => $isExempt,
                'notes' => $notes,
            ],
            description: "Soll-Stunden aktualisiert für User {$userId}, Jahr {$year}"
        );
    }
}
