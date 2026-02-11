<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Repositories\ReportRepository;

/**
 * Service für Report-Geschäftslogik und rollenbasierte Datenzugriffsteuerung
 */
class ReportService
{
    public function __construct(
        private ReportRepository $reportRepo,
        private AuditService $auditService
    ) {
    }

    /**
     * Bestimmt den Datenzugriffs-Scope basierend auf Benutzerrollen
     *
     * Priorität: Auditor/Admin > Prüfer > Erfasser > Mitglied
     */
    public function getRoleScope(User $user): string
    {
        if ($user->isAdmin() || $user->isAuditor()) {
            return 'all_including_deleted';
        }

        if ($user->isPruefer()) {
            return 'all';
        }

        if ($user->isErfasser()) {
            return 'erfasser';
        }

        return 'own';
    }

    /**
     * Kann der User das Mitglied-Filter-Dropdown sehen?
     */
    public function canFilterByMember(User $user): bool
    {
        return $user->isPruefer() || $user->isAuditor() || $user->isAdmin();
    }

    /**
     * Report-Daten abrufen (paginiert für Ansicht)
     *
     * @return array{entries: array[], total: int, summary: array}
     */
    public function getReportData(User $user, array $filters, int $page, int $perPage): array
    {
        $roleScope = $this->getRoleScope($user);
        $normalized = $this->normalizeFilters($user, $filters);

        $result = $this->reportRepo->findForReport(
            $user->getId(),
            $roleScope,
            $page,
            $perPage,
            $normalized['status'],
            $normalized['date_from'],
            $normalized['date_to'],
            $normalized['category_id'],
            $normalized['project'],
            $normalized['member_id'],
            $normalized['sort'],
            $normalized['dir']
        );

        $summary = $this->reportRepo->getSummary(
            $user->getId(),
            $roleScope,
            $normalized['status'],
            $normalized['date_from'],
            $normalized['date_to'],
            $normalized['category_id'],
            $normalized['project'],
            $normalized['member_id']
        );

        return [
            'entries' => $result['entries'],
            'total' => $result['total'],
            'summary' => $summary,
        ];
    }

    /**
     * Alle Daten für Export abrufen (ohne Pagination)
     *
     * @return array[]
     */
    public function getExportData(User $user, array $filters): array
    {
        $roleScope = $this->getRoleScope($user);
        $normalized = $this->normalizeFilters($user, $filters);

        return $this->reportRepo->findAllForExport(
            $user->getId(),
            $roleScope,
            $normalized['status'],
            $normalized['date_from'],
            $normalized['date_to'],
            $normalized['category_id'],
            $normalized['project'],
            $normalized['member_id'],
            $normalized['sort'],
            $normalized['dir']
        );
    }

    /**
     * Mitglieder-Liste für Filter-Dropdown
     *
     * @return array[]
     */
    public function getMembersForFilter(User $user): array
    {
        if (!$this->canFilterByMember($user)) {
            return [];
        }

        return $this->reportRepo->findMembersForFilter();
    }

    /**
     * Filter normalisieren und Typen konvertieren
     *
     * @return array{status: ?string, date_from: ?string, date_to: ?string, category_id: ?int, project: ?string, member_id: ?int, sort: string, dir: string}
     */
    private function normalizeFilters(User $user, array $filters): array
    {
        return [
            'status' => $filters['status'] ?? null,
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'category_id' => isset($filters['category_id']) && $filters['category_id'] !== ''
                ? (int) $filters['category_id'] : null,
            'project' => $filters['project'] ?? null,
            'member_id' => $this->canFilterByMember($user) && isset($filters['member_id']) && $filters['member_id'] !== ''
                ? (int) $filters['member_id'] : null,
            'sort' => $filters['sort'] ?? 'work_date',
            'dir' => $filters['dir'] ?? 'DESC',
        ];
    }

    /**
     * Export im Audit-Trail protokollieren (REQ-REP-015/016)
     */
    public function logExport(User $user, string $format, array $filters, int $resultCount): void
    {
        $roleScope = $this->getRoleScope($user);

        $this->auditService->log(
            action: 'export',
            tableName: 'work_entries',
            description: "Report-Export: {$format}, {$resultCount} Einträge",
            metadata: [
                'format' => $format,
                'report_type' => 'work_entries',
                'result_count' => $resultCount,
                'role_scope' => $roleScope,
                'filters' => [
                    'status' => $filters['status'] ?? null,
                    'date_from' => $filters['date_from'] ?? null,
                    'date_to' => $filters['date_to'] ?? null,
                    'category_id' => $filters['category_id'] ?? null,
                    'project' => $filters['project'] ?? null,
                    'member_id' => $filters['member_id'] ?? null,
                ],
            ]
        );
    }
}
