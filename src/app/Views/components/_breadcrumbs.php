<?php
/**
 * Breadcrumb-Navigation
 *
 * Erwartet: $breadcrumbs (Array mit 'label' und optionalem 'url')
 */

use App\Helpers\ViewHelper;
?>
<?php if (!empty($breadcrumbs) && count($breadcrumbs) > 1): ?>
<nav aria-label="Breadcrumb">
    <ol class="breadcrumb mb-3">
        <?php foreach ($breadcrumbs as $i => $crumb): ?>
            <?php if ($i === count($breadcrumbs) - 1): ?>
                <li class="breadcrumb-item active" aria-current="page"><?= ViewHelper::e($crumb['label']) ?></li>
            <?php else: ?>
                <li class="breadcrumb-item">
                    <a href="<?= ViewHelper::url($crumb['url']) ?>"><?= ViewHelper::e($crumb['label']) ?></a>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ol>
</nav>
<?php endif; ?>
