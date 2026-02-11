<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\SecurityHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * CSRF-Schutz-Middleware
 */
class CsrfMiddleware implements MiddlewareInterface
{
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
