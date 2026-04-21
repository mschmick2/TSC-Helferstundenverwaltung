<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\ViewHelper;
use App\Repositories\SettingsRepository;
use App\Services\AuditService;
use App\Services\EmailService;
use App\Services\SettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller für Admin-Einstellungen
 */
class AdminController extends BaseController
{
    public function __construct(
        private SettingsRepository $settingsRepo,
        private SettingsService $settingsService,
        private AuditService $auditService,
        private EmailService $emailService,
        private array $settings
    ) {
    }

    /**
     * Einstellungen anzeigen (GET /admin/settings)
     */
    public function settings(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $allSettings = $this->settingsRepo->findAll();

        // Settings in Gruppen aufteilen
        $groups = $this->groupSettings($allSettings);

        return $this->render($response, 'admin/settings/index', [
            'title' => 'Systemeinstellungen',
            'user' => $user,
            'settings' => $this->settings,
            'allSettings' => $allSettings,
            'groups' => $groups,
            'breadcrumbs' => [
                ['label' => 'Dashboard', 'url' => '/'],
                ['label' => 'Verwaltung', 'url' => '/admin/users'],
                ['label' => 'Einstellungen'],
            ],
        ]);
    }

    /**
     * Einstellungen speichern (POST /admin/settings)
     */
    public function updateSettings(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = $request->getParsedBody();
        $changedCount = 0;

        // Validierungen
        $errors = $this->validateSettings($data);
        if (!empty($errors)) {
            foreach ($errors as $error) {
                ViewHelper::flash('danger', $error);
            }
            return $this->redirect($response, '/admin/settings');
        }

        // Alle Settings aus DB laden für Vergleich
        $allSettings = $this->settingsRepo->findAll();

        foreach ($allSettings as $key => $setting) {
            if (!array_key_exists($key, $data['settings'] ?? [])) {
                // Boolean-Felder die nicht gesendet werden = false
                if ($setting['setting_type'] === 'boolean') {
                    $newValue = 'false';
                } else {
                    continue;
                }
            } else {
                $newValue = trim($data['settings'][$key] ?? '');
            }

            // Wert normalisieren
            if ($setting['setting_type'] === 'boolean') {
                $newValue = in_array($newValue, ['1', 'on', 'true'], true) ? 'true' : 'false';
            }

            // Nur geänderte Werte speichern
            if ($setting['setting_value'] !== $newValue) {
                $this->settingsService->set($key, $newValue, $user->getId());
                $changedCount++;
            }
        }

        if ($changedCount > 0) {
            ViewHelper::flash('success', "{$changedCount} Einstellung(en) wurden gespeichert.");
        } else {
            ViewHelper::flash('info', 'Keine Änderungen vorgenommen.');
        }

        return $this->redirect($response, '/admin/settings');
    }

    /**
     * Test-E-Mail senden (POST /admin/settings/test-email)
     */
    public function testEmail(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');

        try {
            $this->emailService->send(
                $user->getEmail(),
                'VAES Test-E-Mail',
                '<h1>Test erfolgreich!</h1><p>Diese E-Mail bestätigt, dass die E-Mail-Konfiguration korrekt ist.</p>'
            );
            ViewHelper::flash('success', 'Test-E-Mail wurde an ' . $user->getEmail() . ' gesendet.');
        } catch (\Throwable $e) {
            ViewHelper::flash('danger', 'E-Mail konnte nicht gesendet werden: ' . $e->getMessage());
        }

        return $this->redirect($response, '/admin/settings');
    }

    /**
     * Einstellungen validieren
     *
     * @return string[]
     */
    private function validateSettings(array $data): array
    {
        $errors = [];
        $s = $data['settings'] ?? [];

        if (isset($s['session_timeout_minutes']) && (int) $s['session_timeout_minutes'] < 5) {
            $errors[] = 'Session-Timeout muss mindestens 5 Minuten betragen.';
        }

        if (isset($s['max_login_attempts']) && (int) $s['max_login_attempts'] < 1) {
            $errors[] = 'Maximale Fehlversuche muss mindestens 1 betragen.';
        }

        if (isset($s['data_retention_years']) && (int) $s['data_retention_years'] < 3) {
            $errors[] = 'Aufbewahrungsfrist muss mindestens 3 Jahre betragen.';
        }

        if (isset($s['invitation_expiry_days']) && (int) $s['invitation_expiry_days'] < 1) {
            $errors[] = 'Einladungsgültigkeit muss mindestens 1 Tag betragen.';
        }

        if (isset($s['cron_min_interval_seconds']) && (int) $s['cron_min_interval_seconds'] < 60) {
            $errors[] = 'Scheduler-Mindestintervall muss mindestens 60 Sekunden betragen (sonst riskierst du Mehrfach-Mails).';
        }

        return $errors;
    }

    /**
     * Cron-Token rotieren (POST /admin/settings/cron-token).
     *
     * Der Klartext-Token wird serverseitig erzeugt, SHA-256-gehasht in der DB
     * abgelegt und EINMALIG als Flash-Message angezeigt. Wer den Token nicht
     * sofort kopiert, muss neu rotieren — wir behalten ihn nirgends im Klartext.
     */
    public function rotateCronToken(Request $request, Response $response): Response
    {
        $user   = $request->getAttribute('user');
        $action = (string) ($request->getParsedBody()['action'] ?? 'rotate');

        if ($action === 'remove') {
            $this->settingsService->set('cron_external_token_hash', '', $user->getId());
            $this->auditService->log(
                action: 'config_change',
                tableName: 'settings',
                description: 'Cron-Token entfernt — externer Pinger deaktiviert',
                metadata: ['setting_key' => 'cron_external_token_hash', 'operation' => 'remove']
            );
            ViewHelper::flash('warning', 'Cron-Token wurde entfernt. Der externe Pinger ist jetzt deaktiviert.');
            return $this->redirect($response, '/admin/settings');
        }

        // Neuer Token: 32 Bytes Zufall = 64 hex Zeichen, gut fuer HTTP-Header.
        $plain = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $plain);

        $this->settingsService->set('cron_external_token_hash', $hash, $user->getId());
        $this->auditService->log(
            action: 'config_change',
            tableName: 'settings',
            description: 'Cron-Token rotiert',
            metadata: ['setting_key' => 'cron_external_token_hash', 'operation' => 'rotate']
        );

        // Klartext-Token in separater Session-Variable — das Settings-View rendert
        // sie EINMALIG als prominenten Block und loescht sie danach. Wir packen den
        // Token bewusst NICHT in die Flash-Message, weil die HTML-eskapiert wird
        // und der user-select-all-Helfer dadurch verloren geht.
        //
        // TTL 5 Minuten: Normalerweise greift die View-Seite direkt nach dem
        // Redirect und loescht die Variable. Falls der Admin den Redirect
        // abbricht (Logout, Browser-Tab zu) wuerde der Klartext sonst bis zum
        // Session-Ende im Session-Store liegen — das fangen wir hier ab.
        $_SESSION['_cron_token_plain']     = $plain;
        $_SESSION['_cron_token_plain_exp'] = time() + 300;

        ViewHelper::flash(
            'success',
            'Neuer Cron-Token erzeugt. Bitte JETZT kopieren und im externen Pinger '
            . 'hinterlegen — er wird nach dem Verlassen dieser Seite nicht mehr angezeigt.'
        );

        return $this->redirect($response, '/admin/settings');
    }

    /**
     * Settings in logische Gruppen aufteilen
     */
    private function groupSettings(array $allSettings): array
    {
        $groups = [
            'general' => [
                'label' => 'Allgemein',
                'icon' => 'bi-info-circle',
                'keys' => ['app_name', 'vereinsname', 'vereinslogo_path'],
            ],
            'security' => [
                'label' => 'Sicherheit',
                'icon' => 'bi-shield-lock',
                'keys' => ['session_timeout_minutes', 'max_login_attempts', 'lockout_duration_minutes', 'require_2fa'],
            ],
            'reminders' => [
                'label' => 'Erinnerungen',
                'icon' => 'bi-bell',
                'keys' => ['reminder_days', 'reminder_enabled'],
            ],
            'notifications' => [
                'label' => 'Benachrichtigungen / Scheduler',
                'icon' => 'bi-broadcast',
                'keys' => ['notifications_enabled', 'cron_min_interval_seconds', 'cron_last_run_at'],
            ],
            'target_hours' => [
                'label' => 'Soll-Stunden',
                'icon' => 'bi-bullseye',
                'keys' => ['target_hours_enabled', 'target_hours_default'],
            ],
            'retention' => [
                'label' => 'Datenaufbewahrung',
                'icon' => 'bi-archive',
                'keys' => ['data_retention_years'],
            ],
            'invitations' => [
                'label' => 'Einladungen',
                'icon' => 'bi-envelope-plus',
                'keys' => ['invitation_expiry_days'],
            ],
            'email' => [
                'label' => 'E-Mail / SMTP',
                'icon' => 'bi-envelope',
                'keys' => ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'email_from_address', 'email_from_name'],
            ],
            'fields' => [
                'label' => 'Pflichtfelder',
                'icon' => 'bi-input-cursor-text',
                'keys' => ['field_datum_required', 'field_zeit_von_required', 'field_zeit_bis_required', 'field_stunden_required', 'field_kategorie_required', 'field_projekt_required', 'field_beschreibung_required'],
            ],
            'locks' => [
                'label' => 'Bearbeitungssperren',
                'icon' => 'bi-lock',
                'keys' => ['lock_timeout_minutes'],
            ],
        ];

        // Settings den Gruppen zuordnen
        foreach ($groups as $groupKey => &$group) {
            $group['settings'] = [];
            foreach ($group['keys'] as $key) {
                if (isset($allSettings[$key])) {
                    $group['settings'][$key] = $allSettings[$key];
                }
            }
        }

        return $groups;
    }
}
