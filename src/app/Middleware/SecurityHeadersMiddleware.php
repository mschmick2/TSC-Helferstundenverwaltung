<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Security-Header-Middleware fuer VAES.
 *
 * Setzt Content-Security-Policy + begleitende Sicherheits-Header auf jeder
 * Response — auch bei Redirects und Fehlerseiten, weil die Middleware aeusserst
 * in der Kette registriert wird (siehe src/public/index.php).
 *
 * Motivation: Die `.htaccess` enthaelt bereits eine CSP, aber der PHP-Built-in-
 * Dev-Server (`php -S`) liest `.htaccess` nicht. Damit CSP-Verstoesse lokal im
 * Browser-DevTool sichtbar werden und die Produktion eine zweite Verteidigungs-
 * linie hat, setzen wir die Header hier zusaetzlich PHP-seitig.
 *
 * CSP-Philosophie (Stand "Safe Improvements", CLAUDE.md §8 Nr. 3):
 *   - default-src 'self'                              strikt per Default
 *   - script-src  'self' 'unsafe-inline' cdn.jsdelivr bewusst locker; Nonce-
 *                                                     Rollout folgt in eigener
 *                                                     Iteration
 *   - style-src   'self' 'unsafe-inline' cdn.jsdelivr Bootstrap braucht inline
 *   - object-src  'none'                              keine Plugins/Flash
 *   - base-uri    'self'                              XSS-Hijack via <base>
 *   - form-action 'self'                              Submit nur zur eigenen App
 *   - frame-ancestors 'self'                          Clickjacking-Schutz
 *   - upgrade-insecure-requests                       Mixed-Content blockieren
 *
 * HSTS nur auf HTTPS — sonst koennte ein versehentlich gestarteter HTTP-Server
 * die Domain fuer den Browser auf Dauer HTTPS-locken.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "font-src 'self' https://cdn.jsdelivr.net",
            "img-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            'upgrade-insecure-requests',
        ]);

        $response = $response
            ->withHeader('Content-Security-Policy', $csp)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader(
                'Permissions-Policy',
                'geolocation=(), microphone=(), camera=(), payment=(), usb=()'
            );

        if ($this->isHttps($request)) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=63072000; includeSubDomains'
            );
        }

        return $response;
    }

    private function isHttps(Request $request): bool
    {
        $server = $request->getServerParams();

        if (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') {
            return true;
        }

        if (($server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }

        return $request->getUri()->getScheme() === 'https';
    }
}
