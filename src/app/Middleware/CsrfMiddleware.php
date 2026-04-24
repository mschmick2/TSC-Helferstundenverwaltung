<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\SecurityHelper;
use App\Services\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * CSRF-Schutz-Middleware.
 *
 * Modul 6 I8 Phase 1: bei CSRF-Failure (ungueltiges Token) wird ein
 * audit_log-Eintrag mit action='access_denied' und reason='csrf_invalid'
 * geschrieben, bevor die 403-Response rausgeht. Der AuditService ist
 * nullable-konstruierbar -- fuer Test-Kontexte ohne Container-Setup
 * bleibt der alte CSRF-Verhalten erhalten.
 */
class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ?AuditService $auditService = null,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        // CSRF-Token generieren (für GET-Requests / Formulare)
        SecurityHelper::generateCsrfToken();

        // Bei GET/HEAD/OPTIONS: nur Token bereitstellen
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        // Bei POST/PUT/DELETE: Token validieren
        $body = $request->getParsedBody();
        $token = $body['csrf_token'] ?? null;

        // Auch im Header prüfen (für AJAX/Fetch-Requests)
        if ($token === null) {
            $token = $request->getHeaderLine('X-CSRF-Token') ?: null;
        }

        if (!SecurityHelper::validateCsrfToken($token)) {
            // I8 Phase 1 / Follow-up v: CSRF-Failure als Authorization-
            // Denial auditieren. Audit ist try/catch-geschuetzt (in
            // logAccessDenied), faellt also nicht auf die Nase wenn die
            // DB gerade nicht erreichbar ist. Bei nicht-authentifizierten
            // Requests (z.B. abgelaufene Session) schreibt der Eintrag
            // user_id=NULL -- dokumentiert in AuditService-Docblock.
            if ($this->auditService !== null) {
                $this->auditService->logAccessDenied(
                    route: $request->getUri()->getPath(),
                    method: $request->getMethod(),
                    reason: 'csrf_invalid'
                );
            }

            $basePath = \App\Helpers\ViewHelper::getBasePath();
            $response = new SlimResponse();
            $html = '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
                . '<title>Ung&uuml;ltige Anfrage</title>'
                . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"'
                . ' integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">'
                . '<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">'
                . '</head><body class="bg-light d-flex align-items-center min-vh-100">'
                . '<div class="container"><div class="row justify-content-center"><div class="col-md-6">'
                . '<div class="card shadow-sm"><div class="card-body text-center p-5">'
                . '<i class="bi bi-shield-exclamation text-danger" style="font-size:3rem"></i>'
                . '<h4 class="mt-3">Ung&uuml;ltige Anfrage</h4>'
                . '<p class="text-muted">Das Sicherheitstoken ist abgelaufen oder ung&uuml;ltig. Bitte laden Sie die Seite neu und versuchen Sie es erneut.</p>'
                . '<a href="' . htmlspecialchars($basePath . '/', ENT_QUOTES, 'UTF-8') . '" class="btn btn-primary">'
                . '<i class="bi bi-arrow-left"></i> Zur&uuml;ck zur Startseite</a>'
                . '</div></div></div></div></div></body></html>';
            $response->getBody()->write($html);
            return $response->withStatus(403);
        }

        return $handler->handle($request);
    }
}
