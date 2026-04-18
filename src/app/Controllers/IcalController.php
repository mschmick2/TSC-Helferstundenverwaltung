<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\EventRepository;
use App\Repositories\UserRepository;
use App\Services\IcalService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * iCal-Export (Modul 6 I5):
 *   - GET /events/{id}.ics      : einzelnes Event als Download (Session-auth via Middleware)
 *   - GET /ical/subscribe/{token}: Subscription-Feed (KEINE Session — Token-Auth)
 *
 * Slim-Bridge injected die Route-Args NICHT als Parameter; stattdessen via
 * BaseController::routeArgs($request) aus dem Request-Attribut lesen.
 */
final class IcalController extends BaseController
{
    public function __construct(
        private readonly EventRepository $eventRepo,
        private readonly UserRepository $userRepo,
        private readonly IcalService $icalService,
    ) {
    }

    public function downloadEvent(Request $request, Response $response): Response
    {
        $id = (int) ($this->routeArgs($request)['id'] ?? 0);
        $event = $this->eventRepo->findById($id);
        if ($event === null) {
            return $response->withStatus(404);
        }
        $ics = $this->icalService->renderEvent($event);

        return $this->icalResponse($response, $ics, 'event-' . $id . '.ics');
    }

    /**
     * Abo-Feed ohne Session. Token muss gueltig (64 Hex) sein UND zu einem
     * aktiven User gehoeren. Liefert alle zukuenftigen Events, in denen der
     * User als Teilnehmer eingetragen ist.
     */
    public function subscribe(Request $request, Response $response): Response
    {
        $token = (string) ($this->routeArgs($request)['token'] ?? '');
        $user = $this->userRepo->findByIcalToken($token);
        if ($user === null) {
            return $response->withStatus(404);
        }

        $events = $this->eventRepo->findUserAssignedEvents((int) $user->getId());
        $calName = 'VAES — ' . $user->getVorname() . ' ' . $user->getNachname();
        $ics = $this->icalService->renderFeed($events, $calName);

        return $this->icalResponse($response, $ics, 'vaes-abo.ics');
    }

    private function icalResponse(Response $response, string $ics, string $filename): Response
    {
        $response->getBody()->write($ics);
        return $response
            ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'private, max-age=900'); // 15 min — Kalender-Clients pollen
    }
}
