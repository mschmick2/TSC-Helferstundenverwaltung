<?php

declare(strict_types=1);

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invarianten fuer src/public/js/edit-session.js
 * (Modul 6 I7e-C.1 Phase 3).
 *
 * Pattern wie die anderen JS-Invariants-Test-Dateien (z.B.
 * OptimisticLockControllerWiringTest fuer event-task-tree.js):
 * Substring- und Regex-Pruefungen gegen den File-Inhalt. Kein
 * echter JS-Runtime-Test, aber Regressions-Schutz fuer die
 * wichtigen Vertraege (URLs, Storage-Keys, Polling-Intervall,
 * sendBeacon-Pattern).
 */
final class EditSessionJsInvariantsTest extends TestCase
{
    private const JS_PATH =
        __DIR__ . '/../../../src/public/js/edit-session.js';

    private function read(): string
    {
        return (string) file_get_contents(self::JS_PATH);
    }

    // =========================================================================
    // Konstanten
    // =========================================================================

    public function test_heartbeat_interval_constant_is_30_seconds(): void
    {
        self::assertMatchesRegularExpression(
            '/HEARTBEAT_INTERVAL_MS\s*=\s*30000/',
            $this->read(),
            'Polling-Intervall muss 30000 ms (= 30 s) sein '
            . '(Architect-K3 aus I7e-C G1).'
        );
    }

    public function test_storage_keys_have_vaes_prefix(): void
    {
        $code = $this->read();
        self::assertMatchesRegularExpression(
            "/STORAGE_KEY_SESSION_ID\s*=\s*'vaes_edit_session_id'/",
            $code,
            'STORAGE_KEY_SESSION_ID muss vaes_edit_session_id sein '
            . '(Namespace-Prefix gegen Kollision mit anderen Apps).'
        );
        self::assertMatchesRegularExpression(
            "/STORAGE_KEY_BROWSER_ID\s*=\s*'vaes_browser_session_id'/",
            $code,
            'STORAGE_KEY_BROWSER_ID muss vaes_browser_session_id sein.'
        );
    }

    // =========================================================================
    // sessionStorage-Persistenz (Architect-C1)
    // =========================================================================

    public function test_session_id_uses_sessionStorage_not_localStorage(): void
    {
        $code = $this->read();
        // sessionStorage muss verwendet werden (Architect-C1: Lock-Reload-
        // Survivor-Behavior, aber tab-scoped Multi-Tab).
        self::assertStringContainsString(
            'sessionStorage.setItem',
            $code,
            'edit-session.js muss sessionStorage.setItem verwenden.'
        );
        self::assertStringContainsString(
            'sessionStorage.getItem',
            $code,
            'edit-session.js muss sessionStorage.getItem verwenden.'
        );
        // localStorage waere falsch (cross-tab, nicht gewuenscht).
        self::assertStringNotContainsString(
            'localStorage.setItem',
            $code,
            'edit-session.js darf NICHT localStorage verwenden — die '
            . 'Session-ID muss tab-scoped bleiben (Multi-Tab via R2 mit '
            . 'separaten browser_session_id-Werten).'
        );
    }

    // =========================================================================
    // beforeunload + sendBeacon (Architect-NR1)
    // =========================================================================

    public function test_close_uses_sendBeacon_with_form_encoded_blob(): void
    {
        $code = $this->read();
        self::assertStringContainsString(
            'navigator.sendBeacon',
            $code,
            'Close-Handler muss navigator.sendBeacon nutzen, damit der '
            . 'Request beim beforeunload synchron ausgeliefert wird.'
        );
        self::assertStringContainsString(
            'application/x-www-form-urlencoded',
            $code,
            'sendBeacon-Blob muss URL-encoded form-Body sein '
            . '(konsistent zu entry-lock.js, damit CsrfMiddleware den '
            . 'csrf_token-Form-Field annimmt).'
        );
        self::assertMatchesRegularExpression(
            "/body\.append\(\s*'csrf_token'/",
            $code,
            'sendBeacon-Body muss csrf_token als Form-Field enthalten.'
        );
    }

    public function test_beforeunload_and_pagehide_listeners_register_close(): void
    {
        $code = $this->read();
        self::assertMatchesRegularExpression(
            "/addEventListener\(\s*'beforeunload'\s*,\s*closeSessionBestEffort/",
            $code,
            'Boot muss closeSessionBestEffort als beforeunload-Handler '
            . 'registrieren.'
        );
        self::assertMatchesRegularExpression(
            "/addEventListener\(\s*'pagehide'\s*,\s*closeSessionBestEffort/",
            $code,
            'Boot muss zusaetzlich pagehide registrieren — beforeunload '
            . 'feuert auf Mobile unzuverlaessig (Architect-NR1).'
        );
    }

    // =========================================================================
    // API-Endpunkte (URL-Match zur Backend-Route)
    // =========================================================================

    public function test_api_endpoints_match_backend_routes(): void
    {
        $code = $this->read();
        self::assertStringContainsString(
            "'/api/edit-sessions/start'",
            $code,
            'JS muss POST /api/edit-sessions/start aufrufen.'
        );
        self::assertStringContainsString(
            "'/api/edit-sessions/' + ",
            $code,
            'JS muss POST /api/edit-sessions/{id}/heartbeat bzw. '
            . '/close mit dynamischer ID aufrufen.'
        );
        self::assertStringContainsString(
            "'/api/edit-sessions?event_id='",
            $code,
            'JS muss GET /api/edit-sessions?event_id=X fuer das '
            . 'Polling aufrufen.'
        );
    }

    // =========================================================================
    // CSRF-Header in fetch
    // =========================================================================

    public function test_post_calls_set_csrf_header(): void
    {
        $code = $this->read();
        self::assertStringContainsString(
            "'X-CSRF-Token'",
            $code,
            'apiPost muss X-CSRF-Token-Header setzen (konsistent zu '
            . 'event-task-tree.js).'
        );
        self::assertMatchesRegularExpression(
            "/document\.querySelector\(\s*'meta\[name=\"csrf-token\"\]'\s*\)/",
            $code,
            'CSRF-Token-Lesung muss aus <meta name="csrf-token"> kommen '
            . '(Layout-Rendering in main.php).'
        );
    }

    // =========================================================================
    // Initial-State-Rendering (Architect-C4)
    // =========================================================================

    public function test_boot_reads_initial_sessions_from_data_attribute(): void
    {
        $code = $this->read();
        self::assertStringContainsString(
            'dataset.initialSessions',
            $code,
            'Boot muss data-initial-sessions vom Indicator-Container '
            . 'lesen (Architect-C4: synchron-rendern statt auf Polling '
            . 'zu warten).'
        );
        self::assertStringContainsString(
            'JSON.parse(initialAttr)',
            $code,
            'data-initial-sessions muss als JSON geparst werden.'
        );
        self::assertStringContainsString(
            'renderSessionAlerts(initial)',
            $code,
            'Initial-State muss durch renderSessionAlerts gerendert '
            . 'werden — gleicher Code-Pfad wie Polling-Refresh.'
        );
    }

    // =========================================================================
    // XSS-Schutz: textContent statt innerHTML
    // =========================================================================

    public function test_alert_uses_textContent_not_innerHTML(): void
    {
        $code = $this->read();
        self::assertStringContainsString(
            'span.textContent =',
            $code,
            'Display-Name muss per textContent gesetzt werden (XSS-'
            . 'Schutz: Sonderzeichen im Namen werden nicht als HTML '
            . 'interpretiert).'
        );
        // Doppelt sicher: innerHTML mit User-Daten ist im File nicht da.
        self::assertStringNotContainsString(
            'innerHTML = session',
            $code,
            'innerHTML mit Session-Daten waere XSS-Risiko.'
        );
    }

    // =========================================================================
    // Filter eigene Session
    // =========================================================================

    public function test_render_filters_own_user_session(): void
    {
        $code = $this->read();
        self::assertMatchesRegularExpression(
            '/sessions\.filter\(\s*function\s*\(s\)/',
            $code,
            'renderSessionAlerts muss die Sessions filtern.'
        );
        self::assertStringContainsString(
            '!== currentUserId',
            $code,
            'Filter muss die Session des aktuellen Viewers ausblenden '
            . '(Defense-in-Depth: Server filtert ohnehin).'
        );
    }

    public function test_current_user_id_read_from_meta(): void
    {
        $code = $this->read();
        self::assertMatchesRegularExpression(
            "/meta\[name=\"current-user-id\"\]/",
            $code,
            'currentUserId muss aus <meta name="current-user-id"> '
            . 'kommen.'
        );
    }

    // =========================================================================
    // Duration-Formatierung
    // =========================================================================

    public function test_duration_formatter_handles_singular_plural(): void
    {
        $code = $this->read();
        // Singular- und Plural-Form muessen explizit existieren — sonst
        // sieht der Nutzer "1 Minuten" oder "0 Minuten".
        self::assertStringContainsString(
            "'weniger als einer Minute'",
            $code,
            'formatDuration muss "weniger als einer Minute" als '
            . 'Sub-1-Minute-Text liefern.'
        );
        self::assertStringContainsString(
            "'einer Minute'",
            $code,
            'formatDuration muss "einer Minute" als Singular liefern '
            . '(nicht "1 Minuten").'
        );
        self::assertStringContainsString(
            "' Minuten'",
            $code,
            'formatDuration muss "X Minuten" als Plural liefern.'
        );
    }

    // =========================================================================
    // Follow-up z: programmatic-reload-Flag in closeSessionBestEffort
    // =========================================================================

    public function test_close_handler_checks_programmatic_reload_flag(): void
    {
        $js = $this->read();

        // closeSessionBestEffort muss das Flag vaes_programmatic_reload
        // pruefen und bei gesetztem Flag den sendBeacon-Close
        // ueberspringen. Sonst wird die Session beim Lock-Reload
        // geschlossen und Architect-C1 unterlaufen.
        self::assertMatchesRegularExpression(
            "/sessionStorage\.getItem\(\s*'vaes_programmatic_reload'\s*\)/",
            $js,
            'edit-session.js::closeSessionBestEffort muss das Flag '
            . 'vaes_programmatic_reload aus sessionStorage lesen, damit '
            . 'programmatische Reloads (z.B. aus event-task-tree.js::'
            . 'handleLockConflict) den sendBeacon-Close ueberspringen.'
        );

        // Self-cleanup: das Flag muss irgendwo entfernt werden, damit
        // ein folgender echter User-Close nicht auch uebersprungen
        // wird. Wichtig: NICHT im close-Handler selbst (sonst konsumiert
        // der erste von beforeunload/pagehide das Flag und der zweite
        // macht trotzdem sendBeacon), sondern in boot() nach dem Reload.
        self::assertMatchesRegularExpression(
            "/sessionStorage\.removeItem\(\s*'vaes_programmatic_reload'\s*\)/",
            $js,
            'edit-session.js muss das Flag vaes_programmatic_reload '
            . 'beim boot nach dem Reload entfernen (Post-Reload-Cleanup). '
            . 'Sonst wuerde auch ein nachfolgender echter User-Close den '
            . 'Standard-Pfad verpassen.'
        );
    }
}
