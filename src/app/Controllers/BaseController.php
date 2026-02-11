<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

/**
 * Basis-Controller mit gemeinsamen Hilfsmethoden
 */
abstract class BaseController
{
    /**
     * Route-Argumente aus dem Request extrahieren
     */
    protected function routeArgs(Request $request): array
    {
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        return $route ? $route->getArguments() : [];
    }

    /**
     * View mit Layout rendern
     */
    protected function render(
        Response $response,
        string $view,
        array $data = [],
        string $layout = 'main'
    ): Response {
        $viewPath = __DIR__ . '/../Views/' . $view . '.php';
        $layoutPath = __DIR__ . '/../Views/layouts/' . $layout . '.php';

        // View in Buffer rendern
        extract($data);
        ob_start();
        require $viewPath;
        $content = ob_get_clean();

        // Layout rendern
        $title = $data['title'] ?? 'VAES';
        ob_start();
        require $layoutPath;
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * JSON-Response zurückgeben
     */
    protected function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Redirect-Response (prepends basePath automatisch)
     */
    protected function redirect(Response $response, string $url, int $status = 302): Response
    {
        $basePath = \App\Helpers\ViewHelper::getBasePath();
        if ($basePath !== '' && str_starts_with($url, '/') && !str_starts_with($url, $basePath . '/') && $url !== $basePath) {
            $url = $basePath . $url;
        }
        return $response->withHeader('Location', $url)->withStatus($status);
    }
}
