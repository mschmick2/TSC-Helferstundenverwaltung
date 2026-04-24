<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Helper-Methoden fuer Tree-Aktions-Controller.
 *
 * Extrahiert aus drei Controllern in I7e-B.0.1 (Follow-up n aus G8 I7e-A):
 * - OrganizerEventEditController (I7e-A)
 * - EventAdminController          (I7b1, erweitert in I7e-A)
 * - EventTemplateController       (I7c)
 *
 * Enthaelt nur die Helper, die wirklich kontext-neutral sind:
 *   - treeEditorEnabled — Feature-Flag-Check (nullable-safe fuer Template).
 *   - wantsJson         — Accept-Header-Lookup.
 *   - treeSuccessResponse / treeErrorResponse — generische JSON-/Flash-
 *     Response, Redirect-URL wird vom Caller bereitgestellt (statt wie
 *     frueher controller-spezifisch hart-kodiert).
 *
 * Abweichung von I7e-B G1 A2: normalizeTreeFormInputs und
 * serializeTreeForJson liegen im Event-spezifischen Trait, weil ihre
 * Feldnamen event-spezifisch sind und die Template-Varianten eigene
 * Implementierungen brauchen.
 *
 * Konventions-Voraussetzungen (durch TreeControllerConventionsTest
 * statisch erzwungen):
 *   - `$this->settingsService` (SettingsService oder ?SettingsService)
 *     fuer treeEditorEnabled.
 *   - Methoden `json()` und `redirect()` aus BaseController fuer die
 *     Response-Helper.
 */
trait TreeActionHelpers
{
    /**
     * Feature-Flag events.tree_editor_enabled. Nullable-safe, damit auch
     * der EventTemplateController funktioniert, dessen settingsService
     * per Konstruktor optional ist.
     */
    protected function treeEditorEnabled(): bool
    {
        if ($this->settingsService === null) {
            return false;
        }
        $value = $this->settingsService->getString('events.tree_editor_enabled', '0');
        return $value === '1' || $value === 'true';
    }

    /**
     * Accept-Header-Heuristik: meldet das Tree-Editor-JS JSON-Erwartung?
     */
    protected function wantsJson(Request $request): bool
    {
        return str_contains($request->getHeaderLine('Accept'), 'application/json');
    }

    /**
     * Erfolgs-Response nach einer mutierenden Tree-Action. JSON-Request
     * bekommt `{"status":"ok"}`, klassische Form-Submits einen Redirect
     * auf den uebergebenen Pfad plus Success-Flash.
     *
     * Der `$redirectPath` ist bewusst vom Caller zu liefern, weil er
     * pro Controller verschieden ist (/organizer/events/{id}/editor,
     * /admin/events/{id}, /admin/event-templates/{id}/edit).
     */
    protected function treeSuccessResponse(
        Request $request,
        Response $response,
        string $redirectPath,
        string $flashMessage
    ): Response {
        if ($this->wantsJson($request)) {
            return $this->json($response, ['status' => 'ok']);
        }
        \App\Helpers\ViewHelper::flash('success', $flashMessage);
        return $this->redirect($response, $redirectPath);
    }

    /**
     * Fehler-Response analog zu treeSuccessResponse.
     *
     * @param array<int|string, string> $errors
     */
    protected function treeErrorResponse(
        Request $request,
        Response $response,
        string $redirectPath,
        int $status,
        array $errors
    ): Response {
        if ($this->wantsJson($request)) {
            return $this->json($response, ['status' => 'error', 'errors' => $errors], $status);
        }
        \App\Helpers\ViewHelper::flash('danger', implode(' ', array_map('strval', $errors)));
        return $this->redirect($response, $redirectPath);
    }

    /**
     * 409-Conflict-Response fuer Optimistic-Lock-Konflikte (I7e-B.1 Phase 2).
     *
     * Je nach wantsJson:
     *  - AJAX (Tree-Editor-JS): 409 mit JSON
     *    `{"error":"optimistic_lock_conflict","message":"..."}`.
     *    Tree-Editor-JS liest `result.status === 409` und
     *    uebersetzt zu Toast + Re-Fetch.
     *  - Form-Submit-Fallback: 409 mit Flash + Redirect zum
     *    gleichen Editor, damit der Nutzer den frischen DB-Stand
     *    sieht.
     *
     * Die Message ist bewusst knapp und klar, ohne technische
     * Details — der Tree-Editor-Konflikt ist kein Fehler, sondern
     * ein erwartetes Rennen, das die UI jetzt aufloest.
     */
    protected function lockConflictResponse(
        Request $request,
        Response $response,
        string $redirectPath
    ): Response {
        $message = 'Die Aufgabe wurde zwischenzeitlich von jemand '
            . 'anderem geaendert. Bitte laden Sie die Ansicht neu '
            . 'und versuchen Sie es erneut.';

        if ($this->wantsJson($request)) {
            return $this->json(
                $response,
                [
                    'status'  => 'error',
                    'error'   => 'optimistic_lock_conflict',
                    'message' => $message,
                ],
                409
            );
        }

        \App\Helpers\ViewHelper::flash('warning', $message);
        return $this->redirect($response, $redirectPath)->withStatus(409);
    }
}
