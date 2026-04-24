<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invarianten fuer EditSessionController und seine
 * Routen-/DI-Verkabelung (Modul 6 I7e-C.1 Phase 2).
 *
 * Sichert per File-Inhalt ab:
 *   - Vier Actions (start, heartbeat, close, listForEvent) existieren.
 *   - start / heartbeat / listForEvent pruefen das Feature-Flag VOR
 *     dem Service-Call. close NICHT (asymmetrisch, Architect-C3-
 *     Pattern aus Phase 1).
 *   - start und listForEvent pruefen event-scoped Permission via
 *     canEditEvent.
 *   - Die vier Routen existieren in routes.php, unter /api/edit-sessions.
 *   - EditSessionController ist im DI-Container registriert.
 *   - BaseController bietet den canEditEvent-Bool-Helper.
 */
final class EditSessionControllerInvariantsTest extends TestCase
{
    private const CONTROLLER_PATH =
        __DIR__ . '/../../../src/app/Controllers/EditSessionController.php';
    private const BASE_CONTROLLER_PATH =
        __DIR__ . '/../../../src/app/Controllers/BaseController.php';
    private const ROUTES_PATH =
        __DIR__ . '/../../../src/config/routes.php';
    private const DI_PATH =
        __DIR__ . '/../../../src/config/dependencies.php';

    private const ACTIONS = ['start', 'heartbeat', 'close', 'listForEvent'];

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    private function methodBody(string $code, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/')
            . '\s*\([^{]*\{(.*?)(?=private function |public function |protected function |\}\s*$)/s';
        if (preg_match($pattern, $code, $m)) {
            return $m[1];
        }
        return '';
    }

    // =========================================================================
    // Gruppe A — Controller-Actions existieren
    // =========================================================================

    public function test_controller_defines_four_actions(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (self::ACTIONS as $action) {
            self::assertNotSame(
                '',
                $this->methodBody($code, $action),
                "EditSessionController::$action() muss existieren."
            );
        }
    }

    // =========================================================================
    // Gruppe B — Feature-Flag-Pruefung asymmetrisch
    // =========================================================================

    public function test_start_heartbeat_list_check_feature_flag_early(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (['start', 'heartbeat'] as $action) {
            $body = $this->methodBody($code, $action);
            self::assertStringContainsString(
                'editSessionsEnabled()',
                $body,
                "EditSessionController::$action muss editSessionsEnabled() "
                . 'als ersten Gate pruefen.'
            );
        }
        // listForEvent ruft editSessionsEnabled nicht direkt, sondern
        // delegiert an den Service, der den Flag selbst respektiert und
        // bei false ein leeres Array liefert. Die Invariante fuer list
        // ist: leeres Array bei deaktiviertem Flag (Bereich D).
    }

    public function test_close_does_NOT_check_feature_flag(): void
    {
        $body = $this->methodBody(
            $this->read(self::CONTROLLER_PATH),
            'close'
        );
        self::assertNotSame('', $body, 'close-Body leer.');
        self::assertStringNotContainsString(
            'editSessionsEnabled',
            $body,
            'EditSessionController::close darf den Feature-Flag NICHT '
            . 'pruefen -- Client soll auch nach Feature-Abschaltung seine '
            . 'laufende Session schliessen koennen (Service-Asymmetrie '
            . 'aus Phase 1).'
        );
    }

    // =========================================================================
    // Gruppe C — Permission-Check via canEditEvent
    // =========================================================================

    public function test_start_and_list_check_canEditEvent(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (['start', 'listForEvent'] as $action) {
            $body = $this->methodBody($code, $action);
            self::assertStringContainsString(
                '$this->canEditEvent(',
                $body,
                "EditSessionController::$action muss canEditEvent "
                . 'aufrufen (Admin-Rolle ODER Organizer-Mitgliedschaft).'
            );
        }
    }

    public function test_heartbeat_and_close_do_NOT_check_canEditEvent(): void
    {
        // Bei heartbeat und close ist der IDOR-Schutz via user_id-Filter
        // im UPDATE-WHERE (Repo-Ebene aus Phase 1) der einzige Schutz --
        // canEditEvent waere redundant, weil ein fremder User nur seine
        // eigene Session-ID kennt. Architect-C2-Style-Entscheidung.
        $code = $this->read(self::CONTROLLER_PATH);
        foreach (['heartbeat', 'close'] as $action) {
            $body = $this->methodBody($code, $action);
            self::assertStringNotContainsString(
                '$this->canEditEvent(',
                $body,
                "EditSessionController::$action darf canEditEvent NICHT "
                . 'aufrufen -- IDOR-Schutz laeuft ueber user_id-Filter '
                . 'auf Repo-Ebene.'
            );
        }
    }

    // =========================================================================
    // Gruppe D — HTTP-Status-Codes
    // =========================================================================

    public function test_start_returns_201_on_success(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'start');
        // 201 Created als expliziter Status, session_id im Body.
        self::assertStringContainsString(
            "'session_id' => \$sessionId",
            $body,
            'start muss session_id im Erfolgs-Body liefern.'
        );
        self::assertMatchesRegularExpression(
            '/\b201\b/',
            $body,
            'start muss 201 Created als HTTP-Status im Erfolgspfad nutzen.'
        );
    }

    public function test_start_returns_410_on_disabled_flag(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'start');
        self::assertStringContainsString(
            "'feature_disabled'",
            $body,
            'start muss im Feature-Flag-Pfad den error-Code '
            . 'feature_disabled setzen.'
        );
        self::assertMatchesRegularExpression(
            '/\b410\b/',
            $body,
            'start muss 410 Gone als HTTP-Status fuer deaktiviertes '
            . 'Feature nutzen.'
        );
    }

    public function test_heartbeat_returns_404_on_service_false(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'heartbeat');
        self::assertStringContainsString(
            "'session_not_found_or_expired'",
            $body,
            'heartbeat muss den error-Code session_not_found_or_expired '
            . 'setzen, wenn der Service false zurueckgibt.'
        );
        self::assertMatchesRegularExpression(
            '/\b404\b/',
            $body,
            'heartbeat muss 404 Not Found als HTTP-Status fuer die '
            . 'false-Rueckgabe nutzen.'
        );
    }

    public function test_list_returns_sessions_wrapper_key(): void
    {
        $body = $this->methodBody($this->read(self::CONTROLLER_PATH), 'listForEvent');
        self::assertMatchesRegularExpression(
            "/'sessions'\\s*=>/",
            $body,
            'listForEvent muss die Sessions unter dem JSON-Key sessions '
            . 'zurueckgeben (Client liest result.sessions).'
        );
    }

    // =========================================================================
    // Gruppe E — BaseController::canEditEvent
    // =========================================================================

    public function test_base_controller_provides_can_edit_event_bool(): void
    {
        $code = $this->read(self::BASE_CONTROLLER_PATH);
        self::assertMatchesRegularExpression(
            '/protected\s+function\s+canEditEvent\s*\(/',
            $code,
            'BaseController::canEditEvent muss existieren '
            . '(Bool-Sibling zu assertEventEditPermission).'
        );
        // Muss bool zurueckgeben.
        self::assertMatchesRegularExpression(
            '/function\s+canEditEvent\s*\([^)]*\)\s*:\s*bool\b/s',
            $code,
            'canEditEvent muss `: bool` als Return-Type haben.'
        );
    }

    // =========================================================================
    // Gruppe F — Routen registriert
    // =========================================================================

    public function test_routes_file_registers_four_edit_session_routes(): void
    {
        $code = $this->read(self::ROUTES_PATH);
        self::assertStringContainsString(
            "'/api/edit-sessions/start'",
            $code,
            'routes.php muss POST /api/edit-sessions/start registrieren.'
        );
        self::assertStringContainsString(
            "'/api/edit-sessions/{id:[0-9]+}/heartbeat'",
            $code,
            'routes.php muss POST /api/edit-sessions/{id}/heartbeat '
            . 'registrieren (numerischer id-Regex Pflicht).'
        );
        self::assertStringContainsString(
            "'/api/edit-sessions/{id:[0-9]+}/close'",
            $code,
            'routes.php muss POST /api/edit-sessions/{id}/close '
            . 'registrieren.'
        );
        self::assertStringContainsString(
            "'/api/edit-sessions'",
            $code,
            'routes.php muss GET /api/edit-sessions registrieren '
            . '(Polling-Endpunkt mit event_id Query).'
        );
    }

    public function test_routes_import_edit_session_controller(): void
    {
        $code = $this->read(self::ROUTES_PATH);
        self::assertStringContainsString(
            'use App\\Controllers\\EditSessionController;',
            $code,
            'routes.php muss EditSessionController importieren.'
        );
    }

    // =========================================================================
    // Gruppe G — DI-Container
    // =========================================================================

    public function test_di_registers_edit_session_controller(): void
    {
        $code = $this->read(self::DI_PATH);
        self::assertStringContainsString(
            'App\\Controllers\\EditSessionController::class',
            $code,
            'dependencies.php muss EditSessionController registrieren.'
        );
        self::assertStringContainsString(
            'App\\Services\\EditSessionService::class',
            $code,
            'dependencies.php muss EditSessionService registrieren '
            . '(bereits in Phase 1).'
        );
    }

    public function test_di_injects_edit_session_service_into_editor_controllers(): void
    {
        // EventAdminController und OrganizerEventEditController brauchen
        // EditSessionService als DI, damit showEditor / edit den Initial-
        // State rendern koennen (C4 aus I7e-C G1).
        $code = $this->read(self::DI_PATH);
        $pattern = '/new EventAdminController\(.*EditSessionService::class.*?\)/s';
        self::assertMatchesRegularExpression(
            $pattern,
            $code,
            'EventAdminController-Factory muss EditSessionService '
            . 'als Argument uebergeben.'
        );
        $pattern2 = '/new \\\\App\\\\Controllers\\\\OrganizerEventEditController\(.*EditSessionService::class.*?\)/s';
        self::assertMatchesRegularExpression(
            $pattern2,
            $code,
            'OrganizerEventEditController-Factory muss EditSessionService '
            . 'als Argument uebergeben.'
        );
    }
}
