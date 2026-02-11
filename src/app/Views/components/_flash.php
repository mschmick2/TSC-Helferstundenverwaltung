<?php
/**
 * Flash-Messages Komponente
 */

use App\Helpers\ViewHelper;

$flashMessages = ViewHelper::getFlashMessages();
$iconMap = [
    'success' => 'bi-check-circle-fill',
    'danger' => 'bi-exclamation-triangle-fill',
    'warning' => 'bi-exclamation-circle-fill',
    'info' => 'bi-info-circle-fill',
];

foreach ($flashMessages as $type => $messages): ?>
    <?php foreach ($messages as $message): ?>
        <div class="alert alert-<?= ViewHelper::e($type) ?> alert-dismissible fade show" role="alert">
            <i class="bi <?= $iconMap[$type] ?? 'bi-info-circle' ?> me-1"></i>
            <?= ViewHelper::e($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>
