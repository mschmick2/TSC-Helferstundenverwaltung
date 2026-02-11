<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Middleware zur Authentifizierungsprüfung
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AuthService $authService,
        private SessionRepository $sessionRepository,
        private UserRepository $userRepository,
        private int $sessionLifetime = 1800,
        private bool $require2fa = true
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        // Session-Token prüfen
        $sessionToken = $_SESSION['session_token'] ?? null;
        if ($sessionToken === null) {
            return $this->redirectToLogin($request);
        }

        // DB-Session laden
        $dbSession = $this->sessionRepository->findByToken($sessionToken);
        if ($dbSession === null) {
            // Session abgelaufen oder ungültig
            unset($_SESSION['session_token'], $_SESSION['user_id']);
            return $this->redirectToLogin($request, 'expired');
        }

        // User laden
        $user = $this->userRepository->findById((int) $dbSession['user_id']);
        if ($user === null || !$user->isActive()) {
            unset($_SESSION['session_token'], $_SESSION['user_id']);
            return $this->redirectToLogin($request);
        }

        // 2FA-Prüfung: Wenn 2FA erforderlich aber noch nicht verifiziert
        if (!($_SESSION['2fa_verified'] ?? false) && $user->is2faEnabled()) {
            return $this->redirectTo('/2fa');
        }

        // 2FA-Setup erzwingen: Wenn require_2fa aktiv aber User hat noch kein 2FA eingerichtet
        if ($this->require2fa && !$user->is2faEnabled()) {
            $path = $request->getUri()->getPath();
            $basePath = \App\Helpers\ViewHelper::getBasePath();
            if ($basePath !== '') {
                $path = preg_replace('#^' . preg_quote($basePath, '#') . '#', '', $path);
            }
            $allowedPaths = ['/2fa-setup', '/logout'];
            if (!in_array($path, $allowedPaths, true)) {
                return $this->redirectTo('/2fa-setup');
            }
        }

        // Session verlängern
        $this->sessionRepository->refresh($sessionToken, $this->sessionLifetime);

        // User in Request-Attribut setzen
        $request = $request->withAttribute('user', $user);
        $request = $request->withAttribute('session_id', (int) $dbSession['id']);

        // User-Daten in Session aktuell halten
        $_SESSION['user_id'] = $user->getId();

        return $handler->handle($request);
    }

    private function redirectToLogin(Request $request, ?string $reason = null): Response
    {
        $url = '/login';
        if ($reason !== null) {
            $url .= '?reason=' . urlencode($reason);
        }
        return $this->redirectTo($url);
    }

    private function redirectTo(string $url): Response
    {
        $basePath = \App\Helpers\ViewHelper::getBasePath();
        if ($basePath !== '' && str_starts_with($url, '/') && !str_starts_with($url, $basePath . '/')) {
            $url = $basePath . $url;
        }
        $response = new SlimResponse();
        return $response->withHeader('Location', $url)->withStatus(302);
    }
}
