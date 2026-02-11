<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\WorkEntry;
use App\Repositories\CategoryRepository;
use App\Services\CsvExportService;
use App\Services\PdfService;
use App\Services\ReportService;
use App\Services\SettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller für Reports und Exporte
 */
class ReportController extends BaseController
{
    public function __construct(
        private ReportService $reportService,
        private PdfService $pdfService,
        private CsvExportService $csvExportService,
        private CategoryRepository $categoryRepo,
        private SettingsService $settingsService,
        private array $settings
    ) {
    }

    /**
     * Report-Hauptseite (GET /reports)
     */
    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = 25;

        $filters = $this->extractFilters($params);

        $reportData = $this->reportService->getReportData($user, $filters, $page, $perPage);
        $categories = $this->categoryRepo->findAllActive();
        $members = $this->reportService->getMembersForFilter($user);
        $canFilterByMember = $this->reportService->canFilterByMember($user);

        return $this->render($response, 'reports/index', [
            'title' => 'Reports',
            'user' => $user,
            'settings' => $this->settings,
            'entries' => $reportData['entries'],
            'total' => $reportData['total'],
            'summary' => $reportData['summary'],
            'categories' => $categories,
            'members' => $members,
            'canFilterByMember' => $canFilterByMember,
            'filters' => $filters,
            'page' => $page,
            'perPage' => $perPage,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Reports'],
            ],
        ]);
    }

    /**
     * PDF-Export (GET /reports/export/pdf)
     */
    public function exportPdf(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();
        $filters = $this->extractFilters($params);

        $entries = $this->reportService->getExportData($user, $filters);
        $canFilterByMember = $this->reportService->canFilterByMember($user);

        // Zusammenfassung für PDF
        $summary = [
            'total_hours' => 0.0,
            'entry_count' => count($entries),
            'count_by_status' => [],
        ];
        $statusCounts = [];
        foreach ($entries as $entry) {
            $summary['total_hours'] += (float) ($entry['hours'] ?? 0);
            $status = $entry['status'] ?? 'unbekannt';
            if (!isset($statusCounts[$status])) {
                $statusCounts[$status] = 0;
            }
            $statusCounts[$status]++;
        }
        foreach ($statusCounts as $status => $cnt) {
            $summary['count_by_status'][] = ['status' => $status, 'cnt' => $cnt];
        }

        // Filter-Labels für PDF (lesbare Kategorie/Mitglied-Namen)
        $pdfFilters = $filters;
        if (!empty($filters['category_id'])) {
            $categories = $this->categoryRepo->findAllActive();
            foreach ($categories as $cat) {
                if ($cat->getId() === (int) $filters['category_id']) {
                    $pdfFilters['category_name'] = $cat->getName();
                    break;
                }
            }
        }
        if (!empty($filters['member_id']) && $canFilterByMember) {
            $members = $this->reportService->getMembersForFilter($user);
            foreach ($members as $m) {
                if ((int) $m['id'] === (int) $filters['member_id']) {
                    $pdfFilters['member_name'] = $m['vollname'];
                    break;
                }
            }
        }

        $pdfContent = $this->pdfService->generateWorkEntryReport(
            $entries,
            $summary,
            $pdfFilters,
            $user->getVollname(),
            $canFilterByMember
        );

        // Export im Audit-Trail protokollieren
        $this->reportService->logExport($user, 'pdf', $filters, count($entries));

        // Dateiname
        $filename = 'Arbeitsstunden_Report_' . date('Y-m-d_His') . '.pdf';

        $response->getBody()->write($pdfContent);

        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    /**
     * CSV-Export (GET /reports/export/csv)
     */
    public function exportCsv(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();
        $filters = $this->extractFilters($params);

        $entries = $this->reportService->getExportData($user, $filters);
        $canFilterByMember = $this->reportService->canFilterByMember($user);

        $csvContent = $this->csvExportService->generateWorkEntryCsv($entries, $canFilterByMember);

        // Export im Audit-Trail protokollieren
        $this->reportService->logExport($user, 'csv', $filters, count($entries));

        // Dateiname
        $filename = 'Arbeitsstunden_Report_' . date('Y-m-d_His') . '.csv';

        $response->getBody()->write($csvContent);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    /**
     * Filter aus Query-Parametern extrahieren
     */
    private function extractFilters(array $params): array
    {
        $status = $params['status'] ?? null;
        if ($status !== null && !array_key_exists($status, WorkEntry::STATUS_LABELS)) {
            $status = null;
        }

        return [
            'status' => $status,
            'date_from' => $params['date_from'] ?? null,
            'date_to' => $params['date_to'] ?? null,
            'category_id' => $params['category_id'] ?? null,
            'project' => $params['project'] ?? null,
            'member_id' => $params['member_id'] ?? null,
            'sort' => $params['sort'] ?? 'work_date',
            'dir' => $params['dir'] ?? 'DESC',
        ];
    }
}
