<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Repository für Audit-Trail-Einsicht (nur lesen)
 */
class AuditRepository
{
    public function __construct(
        private \PDO $pdo
    ) {
    }

    /**
     * Paginierte Audit-Log-Einträge mit Filtern
     *
     * @return array{entries: array[], total: int}
     */
    public function findPaginated(
        int $page = 1,
        int $perPage = 50,
        ?string $action = null,
        ?int $userId = null,
        ?string $tableName = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $entryNumber = null
    ): array {
        $where = [];
        $params = [];

        if ($action !== null && $action !== '') {
            $where[] = 'al.action = :action';
            $params['action'] = $action;
        }

        if ($userId !== null) {
            $where[] = 'al.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        if ($tableName !== null && $tableName !== '') {
            $where[] = 'al.table_name = :table_name';
            $params['table_name'] = $tableName;
        }

        if ($dateFrom !== null && $dateFrom !== '') {
            $where[] = 'al.created_at >= :date_from';
            $params['date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null && $dateTo !== '') {
            $where[] = 'al.created_at <= :date_to';
            $params['date_to'] = $dateTo . ' 23:59:59';
        }

        if ($entryNumber !== null && $entryNumber !== '') {
            $where[] = 'al.entry_number LIKE :entry_number';
            $params['entry_number'] = "%{$entryNumber}%";
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total zählen
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log al {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Daten laden
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT al.*, CONCAT(u.vorname, ' ', u.nachname) AS user_name
                FROM audit_log al
                LEFT JOIN users u ON al.user_id = u.id
                {$whereSql}
                ORDER BY al.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return ['entries' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * Einzelnen Audit-Eintrag laden
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT al.*, CONCAT(u.vorname, ' ', u.nachname) AS user_name
             FROM audit_log al
             LEFT JOIN users u ON al.user_id = u.id
             WHERE al.id = :id"
        );
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch();
        return $data !== false ? $data : null;
    }

    /**
     * Eindeutige Aktionstypen für Filter
     *
     * @return string[]
     */
    public function getDistinctActions(): array
    {
        $stmt = $this->pdo->query(
            "SELECT DISTINCT action FROM audit_log ORDER BY action"
        );
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Eindeutige Tabellennamen für Filter
     *
     * @return string[]
     */
    public function getDistinctTableNames(): array
    {
        $stmt = $this->pdo->query(
            "SELECT DISTINCT table_name FROM audit_log WHERE table_name IS NOT NULL ORDER BY table_name"
        );
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
