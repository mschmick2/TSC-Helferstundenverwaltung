<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\AuthenticationException;
use App\Helpers\SecurityHelper;
use App\Helpers\ViewHelper;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\EmailService;
use App\Services\RateLimitService;
use App\Services\TotpService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Controller für Authentifizierung
 */
class AuthController extends BaseController
{
    public function __construct(
        private AuthService $authService,
        private TotpService $totpService,
        private EmailService $emailService,
        private AuditService $auditService,
        private UserRepository $userRepository,
        private RateLimitService $rateLimitService,
        private array $settings
    ) {
    }

    // =========================================================================
    // Login
    // =========================================================================

    /**
     * Login-Formular anzeigen
     */
    public function showLogin(Request $request, Response $response): Response
    {
        // Wenn schon eingeloggt → Dashboard
        if ($this->authService->isAuthenticated()) {
            return $this->redirect($response, '/');
        }

        $params = $request->getQueryParams();
        $reason = $params['reason'] ?? null;
        $data = [
            'reason' => $reason,
            'settings' => $this->settings,
        ];

        return $this->render($response, 'auth/login', $data, 'auth');
    }

    /**
     * Login verarbeiten
     */
    public function login(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $ipAddress = $this->getClientIp($request);

        // Rate-Limiting: Max. 20 Versuche pro IP in 15 Minuten
        if (!$this->rateLimitService->isAllowed($ipAddress, 'login', 20, 900)) {
            ViewHelper::flash('danger', 'Zu viele Anmeldeversuche. Bitte versuchen Sie es später erneut.');
            return $this->redirect($response, '/login');
        }
        $this->rateLimitService->recordAttempt($ipAddress, 'login');

        if ($email === '' || $password === '') {
            ViewHelper::flash('danger', 'Bitte geben Sie E-Mail und Passwort ein.');
            return $this->redirect($response, '/login');
        }

        try {
            $user = $this->authService->authenticate($email, $password, $ipAddress);
        } catch (AuthenticationException $e) {
            ViewHelper::flash('danger', $e->getMessage());
            ViewHelper::flashOldInput(['email' => $email]);
            return $this->redirect($response, '/login');
        }

        // 2FA erforderlich?
        if ($user->is2faEnabled()) {
            $this->authService->setPending2fa($user);

            // E-Mail-2FA: Code senden
            if ($user->isEmail2faEnabled() && !$user->isTotpEnabled()) {
                $code = $this->totpService->generateEmailCode($user->getId());
                $this->emailService->send2faCode($user->getEmail(), $user->getVorname(), $code);
            }

            return $this->redirect($response, '/2fa');
        }

        // Kein 2FA → Prüfen ob 2FA-Setup erforderlich
        $require2fa = $this->settings['security']['require_2fa'] ?? true;
        if ($require2fa) {
            // Temporär einloggen für 2FA-Setup
            $this->authService->completeLogin(
                $user,
                $ipAddress,
                $request->getHeaderLine('User-Agent')
            );
            return $this->redirect($response, '/2fa-setup');
        }

        // Direkt einloggen
        $this->authService->completeLogin(
            $user,
            $ipAddress,
            $request->getHeaderLine('User-Agent')
        );

        ViewHelper::flash('success', 'Willkommen, ' . $user->getVorname() . '!');
        return $this->redirect($response, '/');
    }

    // =========================================================================
    // 2FA
    // =========================================================================

    /**
     * 2FA-Code-Eingabe anzeigen
     */
    public function show2fa(Request $request, Response $response): Response
    {
        $pending = $this->authService->getPending2fa();
        if ($pending === null) {
            return $this->redirect($response, '/login');
        }

        $data = [
            'method' => $pending['method'],
            'settings' => $this->settings,
        ];

        return $this->render($response, 'auth/2fa', $data, 'auth');
    }

    /**
     * 2FA-Code verifizieren
     */
    public function verify2fa(Request $request, Response $response): Response
    {
        $pending = $this->authService->getPending2fa();
        if ($pending === null) {
            return $this->redirect($response, '/login');
        }

        $body = $request->getParsedBody();
        $code = trim($body['code'] ?? '');
        $userId = $pending['user_id'];
        $method = $pending['method'];

        if ($code === '') {
            ViewHelper::flash('danger', 'Bitte geben Sie den Code ein.');
            return $this->redirect($response, '/2fa');
        }

        // Brute-Force-Schutz: Max. 5 Versuche für 2FA-Code
        $maxAttempts = 5;
        $attempts = $_SESSION['2fa_attempts'] ?? 0;
        if ($attempts >= $maxAttempts) {
            $this->authService->clearPending2fa();
            unset($_SESSION['2fa_attempts']);
            $this->auditService->logLoginFailed(
                "user_id:{$userId}",
                $this->getClientIp($request),
                '2FA-Code: Zu viele Fehlversuche'
            );
            ViewHelper::flash('danger', 'Zu viele Fehlversuche. Bitte melden Sie sich erneut an.');
            return $this->redirect($response, '/login');
        }

        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            $this->authService->clearPending2fa();
            return $this->redirect($response, '/login');
        }

        // Code prüfen
        $valid = false;
        if ($method === 'totp' && $user->getTotpSecret() !== null) {
            $valid = $this->totpService->verifyTotp($user->getTotpSecret(), $code);
        } elseif ($method === 'email') {
            $valid = $this->totpService->verifyEmailCode($userId, $code);
        }

        if (!$valid) {
            $_SESSION['2fa_attempts'] = $attempts + 1;
            $remaining = $maxAttempts - $attempts - 1;
            ViewHelper::flash('danger', "Ungültiger oder abgelaufener Code. Noch {$remaining} Versuch(e).");
            return $this->redirect($response, '/2fa');
        }

        // Erfolgreich: Zähler zurücksetzen
        unset($_SESSION['2fa_attempts']);

        // 2FA erfolgreich → Login abschließen
        $this->authService->clearPending2fa();
        $ipAddress = $this->getClientIp($request);
        $this->authService->completeLogin(
            $user,
            $ipAddress,
            $request->getHeaderLine('User-Agent')
        );

        ViewHelper::flash('success', 'Willkommen, ' . $user->getVorname() . '!');
        return $this->redirect($response, '/');
    }

    // =========================================================================
    // 2FA-Setup
    // =========================================================================

    /**
     * 2FA-Einrichtung anzeigen
     */
    public function show2faSetup(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');

        // Wenn 2FA schon eingerichtet → Dashboard
        if ($user->is2faEnabled()) {
            return $this->redirect($response, '/');
        }

        // Neues Secret generieren
        $secret = $this->totpService->generateSecret();
        $_SESSION['pending_totp_secret'] = $secret;

        $provisioningUri = $this->totpService->getProvisioningUri($secret, $user->getEmail());
        $qrCodeDataUri = $this->totpService->getQrCodeDataUri($provisioningUri);

        $data = [
            'user' => $user,
            'secret' => $secret,
            'provisioningUri' => $provisioningUri,
            'qrCodeDataUri' => $qrCodeDataUri,
            'settings' => $this->settings,
        ];

        return $this->render($response, 'auth/2fa-setup', $data, 'auth');
    }

    /**
     * 2FA-Einrichtung verarbeiten
     */
    public function setup2fa(Request $request, Response $response): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $body = $request->getParsedBody();
        $method = $body['method'] ?? 'totp';
        $code = trim($body['code'] ?? '');

        if ($method === 'totp') {
            $secret = $_SESSION['pending_totp_secret'] ?? null;
            if ($secret === null) {
                ViewHelper::flash('danger', 'Sitzung abgelaufen. Bitte versuchen Sie es erneut.');
                return $this->redirect($response, '/2fa-setup');
            }

            // Verifizierungscode prüfen
            if ($code === '' || !$this->totpService->verifyTotp($secret, $code)) {
                ViewHelper::flash('danger', 'Ungültiger Code. Bitte scannen Sie den QR-Code erneut.');
                return $this->redirect($response, '/2fa-setup');
            }

            // TOTP aktivieren
            $this->userRepository->updateTotpSecret($user->getId(), $secret);
            unset($_SESSION['pending_totp_secret']);

            $this->auditService->log(
                'update',
                'users',
                $user->getId(),
                description: 'TOTP-2FA eingerichtet'
            );
        } elseif ($method === 'email') {
            // E-Mail-2FA aktivieren
            $this->userRepository->enableEmail2fa($user->getId());

            $this->auditService->log(
                'update',
                'users',
                $user->getId(),
                description: 'E-Mail-2FA eingerichtet'
            );
        }

        $_SESSION['2fa_verified'] = true;
        ViewHelper::flash('success', 'Zwei-Faktor-Authentifizierung erfolgreich eingerichtet!');
        return $this->redirect($response, '/');
    }

    // =========================================================================
    // Logout
    // =========================================================================

    /**
     * Logout
     */
    public function logout(Request $request, Response $response): Response
    {
        $this->authService->logout();
        // Neue Session starten für Flash-Message nach Session-Destroy
        session_start();
        ViewHelper::flash('info', 'Sie wurden erfolgreich abgemeldet.');
        return $this->redirect($response, '/login');
    }

    // =========================================================================
    // Passwort-Setup (Einladungslink)
    // =========================================================================

    /**
     * Passwort-Setup-Formular anzeigen
     */
    public function showSetupPassword(Request $request, Response $response): Response
    {
        $args = $this->routeArgs($request);
        $token = $args['token'] ?? '';
        $invitation = $this->userRepository->findByInvitationToken($token);

        if ($invitation === null) {
            ViewHelper::flash('danger', 'Ungültiger oder abgelaufener Einladungslink.');
            return $this->redirect($response, '/login');
        }

        // Abgelaufen?
        if (new \DateTime($invitation['invitation_expires_at']) < new \DateTime()) {
            ViewHelper::flash('danger', 'Der Einladungslink ist abgelaufen. Bitte kontaktieren Sie den Administrator.');
            return $this->redirect($response, '/login');
        }

        $data = [
            'token' => $token,
            'vorname' => $invitation['vorname'],
            'settings' => $this->settings,
        ];

        return $this->render($response, 'auth/setup-password', $data, 'auth');
    }

    /**
     * Passwort-Setup verarbeiten
     */
    public function setupPassword(Request $request, Response $response): Response
    {
        $args = $this->routeArgs($request);
        $ipAddress = $this->getClientIp($request);

        // Rate-Limiting: Max. 10 Versuche pro IP in 15 Minuten
        if (!$this->rateLimitService->isAllowed($ipAddress, 'setup-password', 10, 900)) {
            ViewHelper::flash('danger', 'Zu viele Versuche. Bitte versuchen Sie es später erneut.');
            return $this->redirect($response, '/login');
        }
        $this->rateLimitService->recordAttempt($ipAddress, 'setup-password');

        $token = $args['token'] ?? '';
        $invitation = $this->userRepository->findByInvitationToken($token);

        if ($invitation === null || new \DateTime($invitation['invitation_expires_at']) < new \DateTime()) {
            ViewHelper::flash('danger', 'Ungültiger oder abgelaufener Einladungslink.');
            return $this->redirect($response, '/login');
        }

        $body = $request->getParsedBody();
        $password = $body['password'] ?? '';
        $passwordConfirm = $body['password_confirm'] ?? '';

        // Validierung
        if ($password !== $passwordConfirm) {
            ViewHelper::flash('danger', 'Die Passwörter stimmen nicht überein.');
            return $this->redirect($response, "/setup-password/{$token}");
        }

        $errors = SecurityHelper::validatePassword($password);
        if (!empty($errors)) {
            ViewHelper::flash('danger', implode(' ', $errors));
            return $this->redirect($response, "/setup-password/{$token}");
        }

        // Passwort setzen
        $userId = (int) $invitation['id'];
        $hash = SecurityHelper::hashPassword($password);
        $this->userRepository->updatePassword($userId, $hash);
        $this->userRepository->markInvitationUsed((int) $invitation['invitation_id']);
        $this->userRepository->markEmailVerified($userId);

        $this->auditService->log(
            'update',
            'users',
            $userId,
            description: 'Passwort über Einladungslink gesetzt'
        );

        ViewHelper::flash('success', 'Ihr Passwort wurde gesetzt. Bitte melden Sie sich an.');
        return $this->redirect($response, '/login');
    }

    // =========================================================================
    // Passwort vergessen / Reset
    // =========================================================================

    /**
     * "Passwort vergessen"-Formular anzeigen
     */
    public function showForgotPassword(Request $request, Response $response): Response
    {
        return $this->render($response, 'auth/forgot-password', ['settings' => $this->settings], 'auth');
    }

    /**
     * Passwort-Reset anfordern
     */
    public function requestReset(Request $request, Response $response): Response
    {
        $ipAddress = $this->getClientIp($request);

        // Rate-Limiting: Max. 5 Anfragen pro IP in 15 Minuten
        if (!$this->rateLimitService->isAllowed($ipAddress, 'forgot-password', 5, 900)) {
            ViewHelper::flash('danger', 'Zu viele Anfragen. Bitte versuchen Sie es später erneut.');
            return $this->redirect($response, '/forgot-password');
        }
        $this->rateLimitService->recordAttempt($ipAddress, 'forgot-password');

        $body = $request->getParsedBody();
        $email = trim($body['email'] ?? '');

        // Immer gleiche Nachricht (kein Information-Leak)
        $successMessage = 'Falls ein Account mit dieser E-Mail existiert, wurde ein Link zum Zurücksetzen gesendet.';

        if ($email === '') {
            ViewHelper::flash('danger', 'Bitte geben Sie Ihre E-Mail-Adresse ein.');
            return $this->redirect($response, '/forgot-password');
        }

        $user = $this->userRepository->findByEmail($email);
        if ($user !== null) {
            $token = SecurityHelper::generateToken();
            $this->userRepository->createPasswordReset($user->getId(), $token);

            $resetUrl = ($this->settings['app']['url'] ?? '') . '/reset-password/' . $token;
            $this->emailService->sendPasswordResetLink($user->getEmail(), $user->getVorname(), $resetUrl);

            $this->auditService->log(
                'update',
                'users',
                $user->getId(),
                description: 'Passwort-Reset angefordert'
            );
        }

        ViewHelper::flash('info', $successMessage);
        return $this->redirect($response, '/login');
    }

    /**
     * Passwort-Reset-Formular anzeigen
     */
    public function showResetPassword(Request $request, Response $response): Response
    {
        $args = $this->routeArgs($request);
        $token = $args['token'] ?? '';
        $resetData = $this->userRepository->findByResetToken($token);

        if ($resetData === null) {
            ViewHelper::flash('danger', 'Ungültiger oder abgelaufener Reset-Link.');
            return $this->redirect($response, '/login');
        }

        if (new \DateTime($resetData['reset_expires_at']) < new \DateTime()) {
            ViewHelper::flash('danger', 'Der Reset-Link ist abgelaufen.');
            return $this->redirect($response, '/forgot-password');
        }

        $data = [
            'token' => $token,
            'settings' => $this->settings,
        ];

        return $this->render($response, 'auth/reset-password', $data, 'auth');
    }

    /**
     * Passwort zurücksetzen
     */
    public function resetPassword(Request $request, Response $response): Response
    {
        $args = $this->routeArgs($request);
        $ipAddress = $this->getClientIp($request);

        // Rate-Limiting: Max. 10 Versuche pro IP in 15 Minuten
        if (!$this->rateLimitService->isAllowed($ipAddress, 'reset-password', 10, 900)) {
            ViewHelper::flash('danger', 'Zu viele Versuche. Bitte versuchen Sie es später erneut.');
            return $this->redirect($response, '/login');
        }
        $this->rateLimitService->recordAttempt($ipAddress, 'reset-password');

        $token = $args['token'] ?? '';
        $resetData = $this->userRepository->findByResetToken($token);

        if ($resetData === null || new \DateTime($resetData['reset_expires_at']) < new \DateTime()) {
            ViewHelper::flash('danger', 'Ungültiger oder abgelaufener Reset-Link.');
            return $this->redirect($response, '/login');
        }

        $body = $request->getParsedBody();
        $password = $body['password'] ?? '';
        $passwordConfirm = $body['password_confirm'] ?? '';

        if ($password !== $passwordConfirm) {
            ViewHelper::flash('danger', 'Die Passwörter stimmen nicht überein.');
            return $this->redirect($response, "/reset-password/{$token}");
        }

        $errors = SecurityHelper::validatePassword($password);
        if (!empty($errors)) {
            ViewHelper::flash('danger', implode(' ', $errors));
            return $this->redirect($response, "/reset-password/{$token}");
        }

        $userId = (int) $resetData['id'];

        // Passwort ändern + alle Sessions beenden
        $this->authService->changePassword($userId, $password);
        $this->userRepository->markResetUsed((int) $resetData['reset_id']);

        $this->auditService->log(
            'update',
            'users',
            $userId,
            description: 'Passwort über Reset-Link geändert - alle Sessions beendet'
        );

        ViewHelper::flash('success', 'Ihr Passwort wurde geändert. Bitte melden Sie sich erneut an.');
        return $this->redirect($response, '/login');
    }

    // =========================================================================
    // Helper
    // =========================================================================

    /**
     * Client-IP-Adresse ermitteln
     */
    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
