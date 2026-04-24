<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Exceptions\AuthorizationException;
use App\Models\User;
use App\Repositories\EventOrganizerRepository;
use App\Services\AuditService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

/**
 * Basis-Controller mit gemeinsamen Hilfsmethoden
 */
abstract class BaseController
{
    private static ?AuditService $auditService = null;

    /**
     * Bootstrap-Setter fuer den AuditService, einmalig in index.php nach
     * Container-Aufbau gerufen. Analog zu RoleMiddleware::setAuditService
     * (Modul 6 I8 Phase 1).
     *
     * Hintergrund: BaseController ist abstract und seine 15+ Subclasses
     * haben eigene Konstruktoren ohne parent::__construct(). Ein statischer
     * Setter vermeidet eine invasive Konstruktor-Kaskade und haelt die
     * Audit-Anbindung fuer den handleAuthorizationDenial-Helper aus I8
     * G4-ROT-Fix (FU-I8-G4-0) kompakt.
     */
    public static function setAuditService(AuditService $auditService): void
    {
        self::$auditService = $auditService;
    }

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

    /**
     * Pruefen, ob $user das gegebene Event editieren darf:
     *   - User mit Rolle event_admin oder administrator: ja, immer.
     *   - Sonst: nur, wenn User in event_organizers fuer dieses Event sitzt.
     *
     * Wirft AuthorizationException, wenn keine der beiden Bedingungen greift.
     *
     * Short-Circuit: Rollen-Check zuerst (in-memory auf $user), Organizer-Lookup
     * (DB-Query) nur, wenn der Rollen-Check fehlschlaegt. Spart die Query bei
     * jedem event_admin-Request.
     *
     * Doc-Hinweis (G1 I7b, 2026-04-22): Wenn ein dritter Aufrufer-Pfad diese
     * Pruefung braucht (z.B. I7c-Templates-Editor), in einen
     * AuthorizationService extrahieren. Aktuell als BaseController-Methode
     * ausreichend, weil nur EventAdminController-Tree-Actions sie aufrufen
     * und ein eigener Service fuer drei Zeilen Logik Overhead waere.
     */
    protected function assertEventEditPermission(
        User $user,
        int $eventId,
        EventOrganizerRepository $organizerRepo
    ): void {
        if ($user->hasRole('event_admin') || $user->hasRole('administrator')) {
            return;
        }
        if ($organizerRepo->isOrganizer($eventId, $user->getId())) {
            return;
        }
        throw new AuthorizationException(
            'Sie haben keine Berechtigung, dieses Event zu bearbeiten.'
        );
    }

    /**
     * Bool-Variante zu assertEventEditPermission. Gleiches Kriterium
     * (Admin-Rolle ODER Organizer-Membership), aber ohne Exception.
     * Fuer API-Endpunkte, die bei fehlender Berechtigung einen 403-Status
     * zurueckgeben statt eine Authorization-Exception durchreichen wollen.
     *
     * Eingefuehrt in Modul 6 I7e-C.1 Phase 2 fuer EditSessionController.
     */
    protected function canEditEvent(
        User $user,
        int $eventId,
        EventOrganizerRepository $organizerRepo
    ): bool {
        if ($user->hasRole('event_admin') || $user->hasRole('administrator')) {
            return true;
        }
        return $organizerRepo->isOrganizer($eventId, $user->getId());
    }

    /**
     * Zentrale Behandlung einer AuthorizationException aus Controller-
     * Logik: zuerst logAccessDenied mit Kontext aus Request und Exception,
     * dann Flash + Redirect zum uebergebenen Ziel.
     *
     * Hintergrund (Modul 6 I8 G4-ROT-Fix, FU-I8-G4-0): viele Controller-
     * Methoden fangen AuthorizationException selbst ab und bubbeln sie
     * nicht zum Slim-ErrorHandler. Ohne diesen Helper wuerde fuer diese
     * Pfade keine `access_denied`-Audit-Zeile geschrieben -- I8 Phase 1
     * haette seinen Haupt-Zweck (Follow-up v aus CLAUDE.md §8 Nr. 5)
     * verfehlt. Der Helper gleicht das Audit-Verhalten fuer alle
     * Catch-Stellen an, ohne das Bestands-UX (kontextueller Redirect
     * + Flash) zu aendern.
     *
     * Der AuditService wird ueber den statischen Bootstrap-Setter
     * bereitgestellt; wird er nie gesetzt (z.B. im Unit-Test ohne
     * Container-Setup), laeuft der Helper stumm weiter -- Flash und
     * Redirect funktionieren weiterhin.
     *
     * @param AuthorizationException $e              Exception aus Service/Helper.
     * @param Request                $request        Aktueller Request (Route/Method/URI).
     * @param Response               $response       PSR-7-Response-Basis fuer den Redirect.
     * @param string                 $redirectTarget Wohin nach dem Redirect.
     * @param string                 $flashType      Flash-Kategorie (danger|error|warning|info).
     */
    protected function handleAuthorizationDenial(
        AuthorizationException $e,
        Request $request,
        Response $response,
        string $redirectTarget,
        string $flashType = 'danger'
    ): Response {
        if (self::$auditService !== null) {
            self::$auditService->logAccessDenied(
                route: $request->getUri()->getPath(),
                method: $request->getMethod(),
                reason: $e->getReason(),
                metadata: $e->getMetadata()
            );
        }

        \App\Helpers\ViewHelper::flash($flashType, $e->getMessage());
        return $this->redirect($response, $redirectTarget);
    }
}
