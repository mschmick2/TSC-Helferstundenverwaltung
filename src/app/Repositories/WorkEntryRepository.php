<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\WorkEntry;

/**
 * Repository für Arbeitsstunden-Einträge
 */
class WorkEntryRepository
{
    public function __construct(
        private \PDO $pdo
    ) {
    }

    /**
     * Eintrag anhand ID finden (mit Joins)
     */
    public function findById(int $id): ?WorkEntry
    {
        $stmt = $this->pdo->prepare(
            "SELECT we.*,
                    CONCAT(u.vorname, ' ', u.nachname) AS user_name,
                    CONCAT(cb.vorname, ' ', cb.nachname) AS created_by_name,
                    c.name AS category_name,
                    CONCAT(rb.vorname, ' ', rb.nachname) AS reviewed_by_name,
                    (SELECT COUNT(*) FROM work_entry_dialogs wed
                     WHERE wed.work_entry_id = we.id
                     AND wed.is_question = TRUE AND wed.is_answered = FALSE) AS open_questions_count
             FROM work_entries we
             JOIN users u ON we.user_id = u.id
             JOIN users cb ON we.created_by_user_id = cb.id
             LEFT JOIN categories c ON we.category_id = c.id
             LEFT JOIN users rb ON we.reviewed_by_user_id = rb.id
             WHERE we.id = :id AND we.deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch();

        return $data !== false ? WorkEntry::fromArray($data) : null;
    }

    /**
     * Einträge für einen Benutzer (eigene Stundenübersicht)
     *
     * @return array{entries: WorkEntry[], total: int}
     */
    public function findByUser(
        int $userId,
        int $page = 1,
        int $perPage = 20,
        ?string $status = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $categoryId = null,
        string $sortBy = 'work_date',
        string $sortDir = 'DESC'
    ): array {
        $where = ['we.user_id = :user_id', 'we.deleted_at IS NULL'];
        $params = ['user_id' => $userId];

        if ($status !== null && $status !== '') {
            $where[] = 'we.status = :status';
            $params['status'] = $status;
        }
        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = 'we.work_date >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== null && $dateTo !== '') {
            $where[] = 'we.work_date <= :date_to';
            $params['date_to'] = $dateTo;
        }
        if ($categoryId !== null) {
            $where[] = 'we.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        $whereClause = implode(' AND ', $where);

        return $this->queryEntries($whereClause, $params, $page, $perPage, $sortBy, $sortDir);
    }

    /**
     * Einträge zur Prüfung (für Prüfer)
     *
     * @return array{entries: WorkEntry[], total: int}
     */
    public function findForReview(
        int $reviewerUserId,
        int $page = 1,
        int $perPage = 20,
        ?string $status = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $categoryId = null,
        string $sortBy = 'submitted_at',
        string $sortDir = 'ASC'
    ): array {
        // Prüfer sehen eingereichte und in_klaerung Einträge, NICHT eigene (Selbstgenehmigung verhindern)
        $where = [
            'we.deleted_at IS NULL',
            'we.user_id != :reviewer_id',
        ];
        $params = ['reviewer_id' => $reviewerUserId];

        if ($status !== null && $status !== '') {
            $where[] = 'we.status = :status';
            $params['status'] = $status;
        } else {
            $where[] = "we.status IN ('eingereicht', 'in_klaerung')";
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = 'we.work_date >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        if ($dateTo !== null && $dateTo !== '') {
            $where[] = 'we.work_date <= :date_to';
            $params['date_to'] = $dateTo;
        }
        if ($categoryId !== null) {
            $where[] = 'we.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        $whereClause = implode(' AND ', $where);

        return $this->queryEntries($whereClause, $params, $page, $perPage, $sortBy, $sortDir);
    }

    /**
     * Neuen Eintrag erstellen
     */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO work_entries
             (user_id, created_by_user_id, category_id, work_date, time_from, time_to,
              hours, project, description, status)
             VALUES
             (:user_id, :created_by_user_id, :category_id, :work_date, :time_from, :time_to,
              :hours, :project, :description, :status)"
        );

        $stmt->execute([
            'user_id' => $data['user_id'],
            'created_by_user_id' => $data['created_by_user_id'],
            'category_id' => $data['category_id'] ?? null,
            'work_date' => $data['work_date'],
            'time_from' => $data['time_from'] ?? null,
            'time_to' => $data['time_to'] ?? null,
            'hours' => $data['hours'],
            'project' => $data['project'] ?? null,
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? 'entwurf',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Eintrag aktualisieren (nur im Status Entwurf)
     */
    public function update(int $id, array $data, int $expectedVersion): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE work_entries SET
                category_id = :category_id,
                work_date = :work_date,
                time_from = :time_from,
                time_to = :time_to,
                hours = :hours,
                project = :project,
                description = :description,
                version = version + 1
             WHERE id = :id AND version = :version AND deleted_at IS NULL"
        );

        $stmt->execute([
            'id' => $id,
            'category_id' => $data['category_id'] ?? null,
            'work_date' => $data['work_date'],
            'time_from' => $data['time_from'] ?? null,
            'time_to' => $data['time_to'] ?? null,
            'hours' => $data['hours'],
            'project' => $data['project'] ?? null,
            'description' => $data['description'] ?? null,
            'version' => $expectedVersion,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Soft-Delete (deleted_at setzen)
     */
    public function softDelete(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE work_entries SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Status aktualisieren
     */
    public function updateStatus(
        int $id,
        string $newStatus,
        int $expectedVersion,
        array $additionalFields = []
    ): bool {
        $sets = ['status = :status', 'version = version + 1'];
        $params = ['id' => $id, 'status' => $newStatus, 'version' => $expectedVersion];

        if (isset($additionalFields['submitted_at'])) {
            $sets[] = 'submitted_at = :submitted_at';
            $params['submitted_at'] = $additionalFields['submitted_at'];
        }
        if (isset($additionalFields['reviewed_by_user_id'])) {
            $sets[] = 'reviewed_by_user_id = :reviewed_by_user_id';
            $params['reviewed_by_user_id'] = $additionalFields['reviewed_by_user_id'];
        }
        if (isset($additionalFields['reviewed_at'])) {
            $sets[] = 'reviewed_at = :reviewed_at';
            $params['reviewed_at'] = $additionalFields['reviewed_at'];
        }
        if (array_key_exists('rejection_reason', $additionalFields)) {
            $sets[] = 'rejection_reason = :rejection_reason';
            $params['rejection_reason'] = $additionalFields['rejection_reason'];
        }
        if (array_key_exists('return_reason', $additionalFields)) {
            $sets[] = 'return_reason = :return_reason';
            $params['return_reason'] = $additionalFields['return_reason'];
        }
        if (isset($additionalFields['is_corrected'])) {
            $sets[] = 'is_corrected = :is_corrected';
            $params['is_corrected'] = $additionalFields['is_corrected'];
        }
        if (isset($additionalFields['corrected_by_user_id'])) {
            $sets[] = 'corrected_by_user_id = :corrected_by_user_id';
            $params['corrected_by_user_id'] = $additionalFields['corrected_by_user_id'];
        }
        if (isset($additionalFields['corrected_at'])) {
            $sets[] = 'corrected_at = :corrected_at';
            $params['corrected_at'] = $additionalFields['corrected_at'];
        }
        if (array_key_exists('correction_reason', $additionalFields)) {
            $sets[] = 'correction_reason = :correction_reason';
            $params['correction_reason'] = $additionalFields['correction_reason'];
        }
        if (isset($additionalFields['original_hours'])) {
            $sets[] = 'original_hours = :original_hours';
            $params['original_hours'] = $additionalFields['original_hours'];
        }

        $setClause = implode(', ', $sets);

        $stmt = $this->pdo->prepare(
            "UPDATE work_entries SET {$setClause}
             WHERE id = :id AND version = :version AND deleted_at IS NULL"
        );
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Korrektur an einem freigegebenen Eintrag
     */
    public function correctEntry(
        int $id,
        float $newHours,
        int $correctedByUserId,
        string $correctionReason,
        float $originalHours,
        int $expectedVersion
    ): bool {
        $stmt = $this->pdo->prepare(
            "UPDATE work_entries SET
                hours = :new_hours,
                is_corrected = TRUE,
                corrected_by_user_id = :corrected_by,
                corrected_at = NOW(),
                correction_reason = :reason,
                original_hours = :original_hours,
                version = version + 1
             WHERE id = :id AND version = :version AND deleted_at IS NULL"
        );

        $stmt->execute([
            'id' => $id,
            'new_hours' => $newHours,
            'corrected_by' => $correctedByUserId,
            'reason' => $correctionReason,
            'original_hours' => $originalHours,
            'version' => $expectedVersion,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Rohwerte eines Eintrags für Audit-Trail
     */
    public function getRawById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM work_entries WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch();

        return $data !== false ? $data : null;
    }

    // =========================================================================
    // Interne Hilfsmethoden
    // =========================================================================

    /**
     * Gemeinsame Query-Logik für Listenseiten
     *
     * @return array{entries: WorkEntry[], total: int}
     */
    private function queryEntries(
        string $whereClause,
        array $params,
        int $page,
        int $perPage,
        string $sortBy,
        string $sortDir
    ): array {
        // Erlaubte Sortierfelder (gegen SQL-Injection)
        $allowedSort = [
            'work_date' => 'we.work_date',
            'hours' => 'we.hours',
            'status' => 'we.status',
            'entry_number' => 'we.entry_number',
            'created_at' => 'we.created_at',
            'submitted_at' => 'we.submitted_at',
            'category_name' => 'c.name',
            'user_name' => 'u.nachname',
        ];

        $orderColumn = $allowedSort[$sortBy] ?? 'we.work_date';
        $orderDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        // Total Count
        $countSql = "SELECT COUNT(*) FROM work_entries we WHERE {$whereClause}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Paginierte Ergebnisse mit Joins
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT we.*,
                       CONCAT(u.vorname, ' ', u.nachname) AS user_name,
                       CONCAT(cb.vorname, ' ', cb.nachname) AS created_by_name,
                       c.name AS category_name,
                       CONCAT(rb.vorname, ' ', rb.nachname) AS reviewed_by_name,
                       (SELECT COUNT(*) FROM work_entry_dialogs wed
                        WHERE wed.work_entry_id = we.id
                        AND wed.is_question = TRUE AND wed.is_answered = FALSE) AS open_questions_count
                FROM work_entries we
                JOIN users u ON we.user_id = u.id
                JOIN users cb ON we.created_by_user_id = cb.id
                LEFT JOIN categories c ON we.category_id = c.id
                LEFT JOIN users rb ON we.reviewed_by_user_id = rb.id
                WHERE {$whereClause}
                ORDER BY {$orderColumn} {$orderDir}
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $entries = [];
        while ($row = $stmt->fetch()) {
            $entries[] = WorkEntry::fromArray($row);
        }

        return ['entries' => $entries, 'total' => $total];
    }
}
