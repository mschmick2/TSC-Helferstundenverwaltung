<?php
/**
 * Haupt-Layout (nach Login)
 *
 * Variablen: $title, $content, $user, $settings
 */

use App\Helpers\VersionHelper;
use App\Helpers\ViewHelper;

$appVersion = $settings['app']['version'] ?? '1.3.0';
$vereinsname = $settings['verein']['name'] ?? 'VAES';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= ViewHelper::e($_SESSION['csrf_token'] ?? '') ?>">
    <title><?= ViewHelper::e(($title ?? 'Dashboard') . ' - VAES') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= ViewHelper::url('/css/app.css') ?>" rel="stylesheet">
</head>
<body data-unread-url="<?= ViewHelper::url('/api/unread-dialog-count') ?>">

    <!-- Navbar -->
    <?php require __DIR__ . '/../components/_navbar.php'; ?>

    <!-- Content -->
    <main class="container-fluid py-4">
        <div class="container">
            <!-- Flash-Messages -->
            <?php require __DIR__ . '/../components/_flash.php'; ?>

            <!-- Breadcrumbs -->
            <?php require __DIR__ . '/../components/_breadcrumbs.php'; ?>

            <?= $content ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer mt-auto py-3 bg-light border-top">
        <div class="container text-center">
            <small class="text-muted">
                <?= ViewHelper::e($vereinsname) ?> &mdash;
                <?= ViewHelper::e(VersionHelper::getVersionString($appVersion)) ?>
            </small>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="<?= ViewHelper::url('/js/app.js') ?>"></script>
</body>
</html>
