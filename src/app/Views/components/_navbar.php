<?php
/**
 * Navbar-Komponente
 *
 * Variablen: $user (App\Models\User), $settings
 */

use App\Helpers\ViewHelper;

$vereinsname = $settings['verein']['name'] ?? 'VAES';
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= ViewHelper::url('/') ?>">
            <i class="bi bi-clock-history"></i> VAES
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">

                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link" href="<?= ViewHelper::url('/') ?>">
                        <i class="bi bi-house"></i> Dashboard
                    </a>
                </li>

                <!-- Arbeitsstunden (für alle eingeloggten Benutzer außer Auditor) -->
                <?php if ($user->hasRole('mitglied') || $user->hasRole('erfasser') || $user->hasRole('pruefer') || $user->isAdmin()): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= ViewHelper::url('/entries') ?>">
                        <i class="bi bi-list-check"></i> Arbeitsstunden
                    </a>
                </li>
                <?php endif; ?>

                <!-- Prüfung (nur Prüfer + Admin) -->
                <?php if ($user->canReview()): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= ViewHelper::url('/review') ?>">
                        <i class="bi bi-clipboard-check"></i> Prüfung
                    </a>
                </li>
                <?php endif; ?>

                <!-- Reports -->
                <li class="nav-item">
                    <a class="nav-link" href="<?= ViewHelper::url('/reports') ?>">
                        <i class="bi bi-bar-chart"></i> Reports
                    </a>
                </li>

                <!-- Audit-Trail (Auditor, nicht Admin - Admin hat es im Verwaltungs-Menü) -->
                <?php if ($user->hasRole('auditor') && !$user->isAdmin()): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= ViewHelper::url('/audit') ?>">
                        <i class="bi bi-journal-text"></i> Audit-Trail
                    </a>
                </li>
                <?php endif; ?>

                <!-- Admin-Menü -->
                <?php if ($user->isAdmin()): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-gear"></i> Verwaltung
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= ViewHelper::url('/admin/users') ?>">
                            <i class="bi bi-people"></i> Mitglieder
                        </a></li>
                        <li><a class="dropdown-item" href="<?= ViewHelper::url('/admin/categories') ?>">
                            <i class="bi bi-tags"></i> Kategorien
                        </a></li>
                        <li><a class="dropdown-item" href="<?= ViewHelper::url('/admin/targets') ?>">
                            <i class="bi bi-bullseye"></i> Soll-Stunden
                        </a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= ViewHelper::url('/admin/audit') ?>">
                            <i class="bi bi-journal-text"></i> Audit-Trail
                        </a></li>
                        <li><a class="dropdown-item" href="<?= ViewHelper::url('/admin/settings') ?>">
                            <i class="bi bi-sliders"></i> Einstellungen
                        </a></li>
                    </ul>
                </li>
                <?php endif; ?>

            </ul>

            <!-- Benutzer-Menü rechts -->
            <ul class="navbar-nav">
                <!-- Nachrichten-Badge -->
                <li class="nav-item me-2">
                    <a class="nav-link position-relative" href="<?= ViewHelper::url('/') ?>"
                       id="nav-unread-badge" title="Ungelesene Nachrichten" style="display:none;">
                        <i class="bi bi-bell-fill"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                              id="nav-unread-count">0</span>
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i>
                        <?= ViewHelper::e($user->getVorname() . ' ' . $user->getNachname()) ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <span class="dropdown-item-text small text-muted">
                                <?= ViewHelper::e(implode(', ', array_map(function ($r) {
                                    return match ($r) {
                                        'mitglied' => 'Mitglied',
                                        'erfasser' => 'Erfasser',
                                        'pruefer' => 'Prüfer',
                                        'auditor' => 'Auditor',
                                        'administrator' => 'Administrator',
                                        default => $r,
                                    };
                                }, $user->getRoles()))) ?>
                            </span>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= ViewHelper::url('/logout') ?>">
                            <i class="bi bi-box-arrow-right"></i> Abmelden
                        </a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
