<?php
/**
 * Pagination-Komponente
 *
 * Variablen: $total, $page, $perPage, $filters (Query-Params)
 */

$totalPages = (int) ceil($total / $perPage);
if ($totalPages <= 1) {
    return;
}

// Query-Parameter für Links beibehalten (ohne 'page')
$queryParams = $filters ?? [];
unset($queryParams['page']);
$baseQuery = http_build_query($queryParams);
$prefix = $baseQuery !== '' ? '?' . $baseQuery . '&page=' : '?page=';
?>
<nav aria-label="Seitennavigation" class="mt-3">
    <div class="d-flex justify-content-between align-items-center">
        <small class="text-muted">
            <?= number_format($total, 0, ',', '.') ?> Einträge, Seite <?= $page ?> von <?= $totalPages ?>
        </small>

        <ul class="pagination pagination-sm mb-0">
            <!-- Zurück -->
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $prefix . ($page - 1) ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>

            <?php
            // Seitenzahlen: max 7 anzeigen
            $start = max(1, $page - 3);
            $end = min($totalPages, $page + 3);

            if ($start > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="<?= $prefix ?>1">1</a>
                </li>
                <?php if ($start > 2): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif;
            endif;

            for ($i = $start; $i <= $end; $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= $prefix . $i ?>"><?= $i ?></a>
                </li>
            <?php endfor;

            if ($end < $totalPages): ?>
                <?php if ($end < $totalPages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
                <li class="page-item">
                    <a class="page-link" href="<?= $prefix . $totalPages ?>"><?= $totalPages ?></a>
                </li>
            <?php endif; ?>

            <!-- Vorwärts -->
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $prefix . ($page + 1) ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        </ul>
    </div>
</nav>
