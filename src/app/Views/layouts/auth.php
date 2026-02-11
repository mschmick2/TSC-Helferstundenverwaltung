<?php
/**
 * Auth-Layout (Login, 2FA, Setup)
 *
 * Variablen: $title, $content, $settings
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
    <title><?= ViewHelper::e($title ?? 'VAES - Anmeldung') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= ViewHelper::url('/css/app.css') ?>" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12 col-sm-10 col-md-8 col-lg-5 col-xl-4">

                <!-- Logo/Titel -->
                <div class="text-center mb-4">
                    <h1 class="h3 fw-bold text-primary">
                        <i class="bi bi-clock-history"></i> VAES
                    </h1>
                    <p class="text-muted"><?= ViewHelper::e($vereinsname) ?></p>
                </div>

                <!-- Flash-Messages -->
                <?php require __DIR__ . '/../components/_flash.php'; ?>

                <!-- Content -->
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <?= $content ?>
                    </div>
                </div>

                <!-- Footer -->
                <div class="text-center mt-3">
                    <small class="text-muted">
                        <?= ViewHelper::e(VersionHelper::getVersionString($appVersion)) ?>
                    </small>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="<?= ViewHelper::url('/js/app.js') ?>"></script>
</body>
</html>
