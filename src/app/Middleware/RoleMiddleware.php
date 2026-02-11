<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\AuthorizationException;
use App\Models\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Middleware zur Rollenprüfung
 */
class RoleMiddleware implements MiddlewareInterface
{
    /**
     * @param string[] $requiredRoles Mindestens eine dieser Rollen muss vorhanden sein
     */
    public function __construct(
        private array $requiredRoles = []
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        /** @var User|null $user */
        $user = $request->getAttribute('user');

        if ($user === null) {
            $basePath = \App\Helpers\ViewHelper::getBasePath();
            $response = new SlimResponse();
            return $response->withHeader('Location', $basePath . '/login')->withStatus(302);
        }

        // Wenn keine Rollen angegeben: nur Login erforderlich
        if (empty($this->requiredRoles)) {
            return $handler->handle($request);
        }

        // Prüfe ob User mindestens eine der erforderlichen Rollen hat
        $hasRole = false;
        foreach ($this->requiredRoles as $role) {
            if ($user->hasRole($role)) {
                $hasRole = true;
                break;
            }
        }

        if (!$hasRole) {
            \App\Helpers\ViewHelper::flash('danger', 'Sie haben keine Berechtigung für diese Aktion.');
            $basePath = \App\Helpers\ViewHelper::getBasePath();
            $response = new SlimResponse();
            return $response->withHeader('Location', $basePath . '/')->withStatus(302);
        }

        return $handler->handle($request);
    }
}
