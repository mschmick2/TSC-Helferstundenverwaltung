<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Repository für Soll-Stunden (yearly_targets)
 */
class YearlyTargetRepository
{
    public function __construct(
        private \PDO $pdo
    ) {
    }

    /**
     * Ziel für einen Benutzer und ein Jahr
     */
    public function findByUserAndYear(int $userId, int $year): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM yearly_targets WHERE user_id = :user_id AND year = :year"
        );
        $stmt->execute(['user_id' => $userId, 'year' => $year]);
        $data = $stmt->fetch();
        return $data !== false ? $data : null;
    }

    /**
     * Alle Ziele für ein Jahr
     *
     * @return array[]
     */
    public function findByYear(int $year): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT yt.*, CONCAT(u.vorname, ' ', u.nachname) AS user_name, u.mitgliedsnummer
             FROM yearly_targets yt
             JOIN users u ON yt.user_id = u.id
             WHERE yt.year = :year AND u.deleted_at IS NULL
             ORDER BY u.nachname ASC, u.vorname ASC"
        );
        $stmt->execute(['year' => $year]);
        return $stmt->fetchAll();
    }

    /**
     * Ziel erstellen oder aktualisieren (UPSERT)
     */
    public function upsert(int $userId, int $year, float $targetHours, bool $isExempt, ?string $notes): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO yearly_targets (user_id, year, target_hours, is_exempt, notes)
             VALUES (:user_id, :year, :target_hours, :is_exempt, :notes)
             ON DUPLICATE KEY UPDATE
                target_hours = :target_hours2, is_exempt = :is_exempt2, notes = :notes2"
        );
        $stmt->execute([
            'user_id' => $userId,
            'year' => $year,
            'target_hours' => $targetHours,
            'is_exempt' => $isExempt ? 1 : 0,
            'notes' => $notes,
            'target_hours2' => $targetHours,
            'is_exempt2' => $isExempt ? 1 : 0,
            'notes2' => $notes,
        ]);
    }

    /**
     * Soll/Ist-Vergleich für alle Mitglieder eines Jahres
     *
     * @return array[]
     */
    public function getComparisonByYear(int $year, int $defaultTargetHours): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id AS user_id, u.mitgliedsnummer,
                    CONCAT(u.vorname, ' ', u.nachname) AS user_name,
                    COALESCE(yt.target_hours, :default_hours) AS target_hours,
                    COALESCE(yt.is_exempt, FALSE) AS is_exempt,
                    COALESCE(yt.notes, '') AS notes,
                    COALESCE(SUM(CASE WHEN we.status = 'freigegeben' THEN we.hours ELSE 0 END), 0) AS actual_hours
             FROM users u
             LEFT JOIN yearly_targets yt ON u.id = yt.user_id AND yt.year = :year
             LEFT JOIN work_entries we ON u.id = we.user_id AND YEAR(we.work_date) = :year2 AND we.deleted_at IS NULL
             WHERE u.deleted_at IS NULL AND u.is_active = TRUE
             GROUP BY u.id, yt.target_hours, yt.is_exempt, yt.notes
             ORDER BY u.nachname ASC, u.vorname ASC"
        );
        $stmt->execute([
            'default_hours' => $defaultTargetHours,
            'year' => $year,
            'year2' => $year,
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Ist-Stunden eines Benutzers in einem Jahr (nur freigegebene)
     */
    public function getActualHours(int $userId, int $year): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(hours), 0) FROM work_entries
             WHERE user_id = :user_id AND YEAR(work_date) = :year
             AND status = 'freigegeben' AND deleted_at IS NULL"
        );
        $stmt->execute(['user_id' => $userId, 'year' => $year]);
        return (float) $stmt->fetchColumn();
    }
}
