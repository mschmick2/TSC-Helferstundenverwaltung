<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthenticationException;
use App\Helpers\SecurityHelper;
use App\Models\User;
use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;

/**
 * Service für Authentifizierung und Session-Management
 */
class AuthService
{
    public function __construct(
        private UserRepository $userRepository,
        private SessionRepository $sessionRepository,
        private AuditService $auditService,
        private array $securitySettings = []
    ) {
    }

    /**
     * Benutzer authentifizieren (Schritt 1: Credentials prüfen)
     *
     * @throws AuthenticationException
     */
    public function authenticate(string $email, string $password, string $ipAddress): User
    {
        $user = $this->userRepository->findByEmail($email);

        // Benutzer nicht gefunden
        if ($user === null) {
            $this->auditService->logLoginFailed($email, $ipAddress, 'Benutzer nicht gefunden');
            throw new AuthenticationException('Ungültige Anmeldedaten.');
        }

        // Account gesperrt?
        if ($user->isLocked()) {
            $this->auditService->logLoginFailed($email, $ipAddress, 'Account gesperrt');
            throw new AuthenticationException(
                'Ihr Account ist vorübergehend gesperrt. Bitte versuchen Sie es später erneut.'
            );
        }

        // Account deaktiviert?
        if (!$user->isActive()) {
            $this->auditService->logLoginFailed($email, $ipAddress, 'Account deaktiviert');
            throw new AuthenticationException('Ihr Account wurde deaktiviert.');
        }

        // Passwort nicht gesetzt (Einladung noch nicht angenommen)?
        if ($user->getPasswordHash() === null) {
            $this->auditService->logLoginFailed($email, $ipAddress, 'Passwort nicht gesetzt');
            throw new AuthenticationException(
                'Bitte verwenden Sie Ihren Einladungslink, um ein Passwort zu setzen.'
            );
        }

        // Passwort prüfen
        if (!SecurityHelper::verifyPassword($password, $user->getPasswordHash())) {
            $attempts = $this->userRepository->incrementFailedAttempts($user->getId());
            $maxAttempts = $this->securitySettings['max_login_attempts'] ?? 5;
            $lockoutDuration = $this->securitySettings['lockout_duration'] ?? 900;

            if ($attempts >= $maxAttempts) {
                $this->userRepository->lockAccount($user->getId(), $lockoutDuration);
                $this->auditService->logLoginFailed($email, $ipAddress, "Account gesperrt nach {$attempts} Fehlversuchen");
                throw new AuthenticationException(
                    'Ihr Account wurde nach zu vielen Fehlversuchen vorübergehend gesperrt.'
                );
            }

            $remaining = $maxAttempts - $attempts;
            $this->auditService->logLoginFailed($email, $ipAddress, "Falsches Passwort (Versuch {$attempts})");
            throw new AuthenticationException(
                "Ungültige Anmeldedaten. Noch {$remaining} Versuch(e) verbleibend."
            );
        }

        // Login erfolgreich: Fehlversuche zurücksetzen
        $this->userRepository->resetFailedAttempts($user->getId());

        return $user;
    }

    /**
     * Vollständigen Login abschließen (nach 2FA, falls erforderlich)
     */
    public function completeLogin(User $user, string $ipAddress, ?string $userAgent): string
    {
        $sessionLifetime = $this->securitySettings['session_lifetime']
            ?? ($this->securitySettings['csrf_token_lifetime'] ?? 1800);

        // DB-Session erstellen
        $token = SecurityHelper::generateToken();
        $this->sessionRepository->create(
            $user->getId(),
            $token,
            $ipAddress,
            $userAgent,
            $sessionLifetime
        );

        // Session-Fixation-Schutz: PHP-Session-ID regenerieren
        session_regenerate_id(true);

        // CSRF-Token rotieren (neues Token nach Login)
        unset($_SESSION['csrf_token']);

        // PHP-Session-Daten setzen
        $_SESSION['session_token'] = $token;
        $_SESSION['user_id'] = $user->getId();
        $_SESSION['2fa_verified'] = true;

        // Letzten Login aktualisieren
        $this->userRepository->updateLastLogin($user->getId());

        // Audit-Log
        $this->auditService->logLogin($user->getId(), $ipAddress);

        // Abgelaufene Sessions bereinigen (gelegentlich)
        if (random_int(1, 10) === 1) {
            $this->sessionRepository->deleteExpired();
        }

        return $token;
    }

    /**
     * Logout - Session zerstören
     */
    public function logout(): void
    {
        $sessionToken = $_SESSION['session_token'] ?? null;
        $userId = $_SESSION['user_id'] ?? null;

        if ($sessionToken !== null) {
            $this->sessionRepository->deleteByToken($sessionToken);
        }

        if ($userId !== null) {
            $this->auditService->logLogout((int) $userId);
        }

        // PHP-Session leeren
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Alle Sessions eines Benutzers beenden (bei Passwortänderung)
     */
    public function destroyAllSessions(int $userId): void
    {
        $this->sessionRepository->deleteAllByUser($userId);
    }

    /**
     * Passwort ändern
     */
    public function changePassword(int $userId, string $newPassword): void
    {
        $hash = SecurityHelper::hashPassword($newPassword);
        $this->userRepository->updatePassword($userId, $hash);

        // Alle Sessions beenden (REQ-SESSION-005)
        $this->destroyAllSessions($userId);

        $this->auditService->log(
            'update',
            'users',
            $userId,
            description: 'Passwort geändert - alle Sessions beendet'
        );
    }

    /**
     * Prüft ob eine gültige Session besteht
     */
    public function isAuthenticated(): bool
    {
        return isset($_SESSION['session_token'], $_SESSION['user_id']);
    }

    /**
     * Aktuellen User aus Session laden
     */
    public function getCurrentUser(): ?User
    {
        $userId = $_SESSION['user_id'] ?? null;
        if ($userId === null) {
            return null;
        }
        return $this->userRepository->findById((int) $userId);
    }

    /**
     * Temporäre Auth-Daten in Session speichern (zwischen Login und 2FA)
     */
    public function setPending2fa(User $user): void
    {
        $_SESSION['pending_2fa_user_id'] = $user->getId();
        $_SESSION['pending_2fa_method'] = $user->isTotpEnabled() ? 'totp' : 'email';
    }

    /**
     * Pending-2FA-Daten aus Session abrufen
     */
    public function getPending2fa(): ?array
    {
        if (!isset($_SESSION['pending_2fa_user_id'])) {
            return null;
        }
        return [
            'user_id' => $_SESSION['pending_2fa_user_id'],
            'method' => $_SESSION['pending_2fa_method'] ?? 'totp',
        ];
    }

    /**
     * Pending-2FA-Daten aus Session löschen
     */
    public function clearPending2fa(): void
    {
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_method']);
    }
}
