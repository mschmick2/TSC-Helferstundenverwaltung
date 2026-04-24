<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\User;
use App\Services\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Middleware zur Rollenprüfung.
 *
 * Modul 6 I8 Phase 1: bei Rollen-Denial wird ein audit_log-Eintrag
 * (action='access_denied') geschrieben -- der AuditService wird als
 * statische Bootstrap-Dependency gesetzt (siehe RoleMiddleware::
 * setAuditService in index.php). Hintergrund: die Middleware wird in
 * routes.php per `new RoleMiddleware([...])` mit Rollen-Parametern
 * instanziiert, also nicht ueber den DI-Container. Ein statischer
 * Setter ist der minimal-invasive Weg, die Audit-Abhaengigkeit ohne
 * Aenderung der >10 bestehenden `->add(new RoleMiddleware(...))`-
 * Aufrufe zu verdrahten.
 */
class RoleMiddleware implements MiddlewareInterface
{
    private static ?AuditService $auditService = null;

    /**
     * Bootstrap-Setter: wird einmalig in index.php nach Container-
     * Aufbau gerufen. Vor dem ersten Request gesetzt, danach fuer
     * alle Requests verfuegbar.
     */
    public static function setAuditService(AuditService $auditService): void
    {
        self::$auditService = $auditService;
    }

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
            // I8 Phase 1 / Follow-up v: Denial wird auditiert, bevor der
            // Redirect rausgeht. Audit ist try/catch-geschuetzt (in
            // logAccessDenied), faellt also nicht auf die Nase, wenn die
            // DB gerade nicht erreichbar ist.
            if (self::$auditService !== null) {
                self::$auditService->logAccessDenied(
                    route: $request->getUri()->getPath(),
                    method: $request->getMethod(),
                    reason: 'missing_role',
                    metadata: [
                        'required_roles' => array_values($this->requiredRoles),
                    ]
                );
            }

            \App\Helpers\ViewHelper::flash('danger', 'Sie haben keine Berechtigung für diese Aktion.');
            $basePath = \App\Helpers\ViewHelper::getBasePath();
            $response = new SlimResponse();
            return $response->withHeader('Location', $basePath . '/')->withStatus(302);
        }

        return $handler->handle($request);
    }
}
