<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Repository für Report-spezifische Queries (Aggregationen, Summen, Export)
 */
class ReportRepository
{
    /** Maximale Anzahl Einträge für Export (Speicherschutz) */
    private const EXPORT_LIMIT = 5000;

    public function __construct(
        private \PDO $pdo
    ) {
    }

    /**
     * Einträge für Report mit rollenbasierter Filterung (paginiert)
     *
     * @return array{entries: array[], total: int}
     */
    public function findForReport(
        int $userId,
        string $roleScope,
        int $page,
        int $perPage,
        ?string $status,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $categoryId,
        ?string $project,
        ?int $memberId,
        string $sortBy,
        string $sortDir
    ): array {
        $where = [];
        $params = [];
        $this->buildScopeConditions($roleScope, $userId, $where, $params);
        $this->buildFilterConditions($status, $dateFrom, $dateTo, $categoryId, $project, $memberId, $where, $params);

        $whereClause = implode(' AND ', $where);

        // Total Count
        $countSql = "SELECT COUNT(*) FROM work_entries we WHERE {$whereClause}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Sortierung
        $orderColumn = $this->getAllowedSortColumn($sortBy);
        $orderDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        // Paginierte Ergebnisse
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT we.*,
                       we.entry_number,
                       CONCAT(u.vorname, ' ', u.nachname) AS user_name,
                       u.mitgliedsnummer,
                       CONCAT(cb.vorname, ' ', cb.nachname) AS created_by_name,
                       c.name AS category_name,
                       CONCAT(rb.vorname, ' ', rb.nachname) AS reviewed_by_name
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

        $entries = $stmt->fetchAll();

        return ['entries' => $entries, 'total' => $total];
    }

    /**
     * Alle Einträge (ohne Pagination) für Export
     *
     * @return array[]
     */
    public function findAllForExport(
        int $userId,
        string $roleScope,
        ?string $status,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $categoryId,
        ?string $project,
        ?int $memberId,
        string $sortBy,
        string $sortDir
    ): array {
        $where = [];
        $params = [];
        $this->buildScopeConditions($roleScope, $userId, $where, $params);
        $this->buildFilterConditions($status, $dateFrom, $dateTo, $categoryId, $project, $memberId, $where, $params);

        $whereClause = implode(' AND ', $where);
        $orderColumn = $this->getAllowedSortColumn($sortBy);
        $orderDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT we.*,
                       we.entry_number,
                       CONCAT(u.vorname, ' ', u.nachname) AS user_name,
                       u.mitgliedsnummer,
                       CONCAT(cb.vorname, ' ', cb.nachname) AS created_by_name,
                       c.name AS category_name,
                       CONCAT(rb.vorname, ' ', rb.nachname) AS reviewed_by_name
                FROM work_entries we
                JOIN users u ON we.user_id = u.id
                JOIN users cb ON we.created_by_user_id = cb.id
                LEFT JOIN categories c ON we.category_id = c.id
                LEFT JOIN users rb ON we.reviewed_by_user_id = rb.id
                WHERE {$whereClause}
                ORDER BY {$orderColumn} {$orderDir}
                LIMIT :export_limit";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('export_limit', self::EXPORT_LIMIT, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Summen-Statistiken für aktuellen Filter
     *
     * @return array{total_hours: float, entry_count: int, count_by_status: array, hours_by_category: array, hours_by_member: array}
     */
    public function getSummary(
        int $userId,
        string $roleScope,
        ?string $status,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $categoryId,
        ?string $project,
        ?int $memberId
    ): array {
        $where = [];
        $params = [];
        $this->buildScopeConditions($roleScope, $userId, $where, $params);
        $this->buildFilterConditions($status, $dateFrom, $dateTo, $categoryId, $project, $memberId, $where, $params);

        $whereClause = implode(' AND ', $where);

        // Gesamt-Stunden + Anzahl
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(we.hours), 0) AS total_hours, COUNT(*) AS entry_count
             FROM work_entries we WHERE {$whereClause}"
        );
        $stmt->execute($params);
        $totals = $stmt->fetch();

        // Anzahl nach Status
        $stmt = $this->pdo->prepare(
            "SELECT we.status, COUNT(*) AS cnt
             FROM work_entries we WHERE {$whereClause}
             GROUP BY we.status ORDER BY we.status"
        );
        $stmt->execute($params);
        $countByStatus = $stmt->fetchAll();

        // Stunden pro Kategorie
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(c.name, 'Ohne Kategorie') AS category_name, COALESCE(SUM(we.hours), 0) AS total_hours
             FROM work_entries we
             LEFT JOIN categories c ON we.category_id = c.id
             WHERE {$whereClause}
             GROUP BY c.name ORDER BY total_hours DESC"
        );
        $stmt->execute($params);
        $hoursByCategory = $stmt->fetchAll();

        // Stunden pro Mitglied (nur bei 'all' oder 'all_including_deleted')
        $hoursByMember = [];
        if (in_array($roleScope, ['all', 'all_including_deleted'], true)) {
            $stmt = $this->pdo->prepare(
                "SELECT CONCAT(u.vorname, ' ', u.nachname) AS member_name,
                        u.mitgliedsnummer,
                        COALESCE(SUM(we.hours), 0) AS total_hours
                 FROM work_entries we
                 JOIN users u ON we.user_id = u.id
                 WHERE {$whereClause}
                 GROUP BY u.id ORDER BY total_hours DESC"
            );
            $stmt->execute($params);
            $hoursByMember = $stmt->fetchAll();
        }

        return [
            'total_hours' => (float) $totals['total_hours'],
            'entry_count' => (int) $totals['entry_count'],
            'count_by_status' => $countByStatus,
            'hours_by_category' => $hoursByCategory,
            'hours_by_member' => $hoursByMember,
        ];
    }

    /**
     * Mitglieder-Liste für Filter-Dropdown (nur für Prüfer/Auditor/Admin)
     *
     * @return array[] [{id, vollname, mitgliedsnummer}]
     */
    public function findMembersForFilter(): array
    {
        $stmt = $this->pdo->query(
            "SELECT u.id, CONCAT(u.vorname, ' ', u.nachname) AS vollname, u.mitgliedsnummer
             FROM users u
             WHERE u.deleted_at IS NULL
             ORDER BY u.nachname, u.vorname"
        );

        return $stmt->fetchAll();
    }

    // =========================================================================
    // Interne Hilfsmethoden
    // =========================================================================

    /**
     * Scope-Bedingungen basierend auf Rolle aufbauen
     */
    private function buildScopeConditions(string $roleScope, int $userId, array &$where, array &$params): void
    {
        switch ($roleScope) {
            case 'own':
                $where[] = 'we.user_id = :scope_user_id';
                $where[] = 'we.deleted_at IS NULL';
                $params['scope_user_id'] = $userId;
                break;

            case 'erfasser':
                $where[] = '(we.user_id = :scope_user_id OR we.created_by_user_id = :scope_user_id2)';
                $where[] = 'we.deleted_at IS NULL';
                $params['scope_user_id'] = $userId;
                $params['scope_user_id2'] = $userId;
                break;

            case 'all':
                $where[] = 'we.deleted_at IS NULL';
                break;

            case 'all_including_deleted':
                // Kein deleted_at-Filter -- Auditor/Admin sieht alles
                $where[] = '1=1';
                break;
        }
    }

    /**
     * Filter-Bedingungen aufbauen
     */
    private function buildFilterConditions(
        ?string $status,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $categoryId,
        ?string $project,
        ?int $memberId,
        array &$where,
        array &$params
    ): void {
        if ($status !== null && $status !== '') {
            $where[] = 'we.status = :filter_status';
            $params['filter_status'] = $status;
        }
        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = 'we.work_date >= :filter_date_from';
            $params['filter_date_from'] = $dateFrom;
        }
        if ($dateTo !== null && $dateTo !== '') {
            $where[] = 'we.work_date <= :filter_date_to';
            $params['filter_date_to'] = $dateTo;
        }
        if ($categoryId !== null) {
            $where[] = 'we.category_id = :filter_category_id';
            $params['filter_category_id'] = $categoryId;
        }
        if ($project !== null && $project !== '') {
            $where[] = 'we.project LIKE :filter_project';
            $params['filter_project'] = '%' . $project . '%';
        }
        if ($memberId !== null) {
            $where[] = 'we.user_id = :filter_member_id';
            $params['filter_member_id'] = $memberId;
        }
    }

    /**
     * Erlaubte Sortier-Spalten (gegen SQL-Injection)
     */
    private function getAllowedSortColumn(string $sortBy): string
    {
        $allowed = [
            'work_date' => 'we.work_date',
            'hours' => 'we.hours',
            'status' => 'we.status',
            'entry_number' => 'we.entry_number',
            'created_at' => 'we.created_at',
            'submitted_at' => 'we.submitted_at',
            'category_name' => 'c.name',
            'user_name' => 'u.nachname',
        ];

        return $allowed[$sortBy] ?? 'we.work_date';
    }
}
