<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\AuditService;
use App\Services\RateLimitService;
use App\Services\SettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Generische Rate-Limit-Middleware fuer authentifizierte Endpunkte
 * (Modul 6 I8 Phase 2 / FU-G4-1).
 *
 * Pro Route-Gruppe wird eine Instanz mit konfiguriertem Bucket und
 * Settings-Keys aus dem DI-Container bezogen. Die drei Buckets sind:
 *   - tree_action              (Tree-Tasks-Actions aller drei Controller)
 *   - edit_session_heartbeat   (30-s-Polling im edit-session.js)
 *   - edit_session_other       (Start + Close, punktuell)
 *
 * Verhalten:
 *   1. Request ohne authentifizierten User (z.B. Middleware-
 *      Reihenfolge-Fehler): Pass-through. Rate-Limit fuer
 *      Unauthenticated macht keinen Sinn -- AuthMiddleware haette
 *      eigentlich schon redirected, aber defensiv nicht blocken.
 *   2. Request unter dem Limit: recordAttemptForUser VOR dem
 *      Handler-Call, dann Handler. "Vor dem Handler" verhindert, dass
 *      ein Angreifer durch provozierte Exceptions (Handler wirft)
 *      den Zaehler umgeht.
 *   3. Request ueber dem Limit: logAccessDenied(reason='rate_limited')
 *      (Architect-C13-Kopplung) + 429-Response mit Retry-After-Header.
 *
 * Middleware-Reihenfolge in routes.php (Architect Q10):
 *   ->add(RateLimitMiddleware)  // zuletzt registriert -> zuletzt ausgefuehrt (vor Controller)
 *   ->add(RoleMiddleware)
 *   ->add(CsrfMiddleware)
 *   ->add(AuthMiddleware)       // zuerst ausgefuehrt
 *
 * Rate-Limit greift damit NACH Auth/Role/CSRF -- Bots, die an Auth
 * scheitern, landen nicht in der rate_limits-Tabelle.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimitService $rateLimiter,
        private readonly SettingsService $settings,
        private readonly AuditService $audit,
        private readonly string $bucket,
        private readonly string $maxSettingKey,
        private readonly string $windowSettingKey,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            // Defensiv: Rate-Limit greift nur bei authentifizierten
            // Requests. Falls Auth-Layer unerwartet einen anonymen
            // Request durchgelassen hat, pass-through -- ein 500er
            // im Handler ist aussagekraeftiger als ein stiller 429.
            return $handler->handle($request);
        }

        $userId = (int) $user->getId();
        $maxAttempts = $this->settings->getInt($this->maxSettingKey, 60);
        $windowSeconds = $this->settings->getInt($this->windowSettingKey, 60);

        if (!$this->rateLimiter->isAllowedForUser(
            $userId,
            $this->bucket,
            $maxAttempts,
            $windowSeconds
        )) {
            // Architect-C13: Rate-Limit-Overflow wird automatisch
            // auditiert. logAccessDenied ist try/catch-geschuetzt --
            // Audit-Failure blockt den 429-Response nicht.
            $this->audit->logAccessDenied(
                route: $request->getUri()->getPath(),
                method: $request->getMethod(),
                reason: 'rate_limited',
                metadata: [
                    'bucket'         => $this->bucket,
                    'limit'          => $maxAttempts,
                    'window_seconds' => $windowSeconds,
                ]
            );

            $response = new SlimResponse();
            $response->getBody()->write(json_encode(
                [
                    'error'       => 'rate_limited',
                    'retry_after' => $windowSeconds,
                    'message'     => 'Zu viele Anfragen. Bitte warten Sie einen Moment.',
                ],
                JSON_UNESCAPED_UNICODE
            ));
            return $response
                ->withHeader('Retry-After', (string) $windowSeconds)
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(429);
        }

        // Attempt VOR Handler registrieren. Damit zaehlt auch ein
        // Handler, der eine Exception wirft -- sonst koennte ein
        // Angreifer durch provozierte Exceptions den Zaehler
        // umgehen.
        $ipAddress = $this->extractIp($request);
        $this->rateLimiter->recordAttemptForUser($userId, $ipAddress, $this->bucket);

        return $handler->handle($request);
    }

    /**
     * Extrahiert die Client-IP aus dem Request. Strato liefert die
     * Client-IP ueber REMOTE_ADDR; Proxy-Pfade waeren in einem anderen
     * Header zu pflegen, sind hier aber nicht Scope.
     */
    private function extractIp(Request $request): string
    {
        $params = $request->getServerParams();
        $ip = $params['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($ip) ? $ip : '0.0.0.0';
    }
}
