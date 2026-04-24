<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use App\Models\EditSessionView;
use PHPUnit\Framework\TestCase;

/**
 * Statische Invarianten fuer das Initial-State-Rendering der Edit-
 * Session-Anzeige (Modul 6 I7e-C.1 Phase 2, Architect-C4).
 *
 * Sichert ab:
 *   - Der gemeinsame Partial _edit_sessions_indicator.php existiert
 *     und rendert einen Container mit #id=edit-sessions-indicator und
 *     den beiden data-Attributen.
 *   - Die drei Editor-Views (admin/editor, organizer/editor,
 *     admin/edit) binden den Partial ein.
 *   - EditSessionView::toJsonReadyArray dedupliziert pro user_id und
 *     filtert den Viewer selbst heraus.
 */
final class EditSessionsIndicatorInvariantsTest extends TestCase
{
    private const PARTIAL_PATH =
        __DIR__ . '/../../../src/app/Views/components/_edit_sessions_indicator.php';
    private const ADMIN_EDITOR_PATH =
        __DIR__ . '/../../../src/app/Views/admin/events/editor.php';
    private const ORGANIZER_EDITOR_PATH =
        __DIR__ . '/../../../src/app/Views/organizer/events/editor.php';
    private const ADMIN_EDIT_PATH =
        __DIR__ . '/../../../src/app/Views/admin/events/edit.php';

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    // =========================================================================
    // Gruppe A — Partial-Struktur
    // =========================================================================

    public function test_partial_file_exists(): void
    {
        self::assertFileExists(self::PARTIAL_PATH);
    }

    public function test_partial_renders_indicator_container(): void
    {
        $code = $this->read(self::PARTIAL_PATH);
        self::assertStringContainsString(
            'id="edit-sessions-indicator"',
            $code,
            'Partial muss einen Anker-Container mit '
            . 'id=edit-sessions-indicator rendern, den das JS in '
            . 'Phase 3 findet.'
        );
        self::assertStringContainsString(
            'data-event-id=',
            $code,
            'Partial muss data-event-id am Container setzen '
            . '(fuer das Polling in Phase 3).'
        );
        self::assertStringContainsString(
            'data-initial-sessions=',
            $code,
            'Partial muss data-initial-sessions am Container setzen '
            . '(Server-seitiger Initial-State, C4).'
        );
    }

    public function test_partial_html_escapes_data_attribute(): void
    {
        $code = $this->read(self::PARTIAL_PATH);
        self::assertStringContainsString(
            'htmlspecialchars(',
            $code,
            'Partial muss htmlspecialchars() auf der JSON-Payload '
            . 'verwenden, damit Apostrophe und Sonderzeichen in '
            . 'Display-Namen das HTML-Attribut nicht brechen.'
        );
        self::assertStringContainsString(
            'ENT_QUOTES',
            $code,
            'htmlspecialchars muss mit ENT_QUOTES aufgerufen werden '
            . '(Apostrophe und Anfuehrungszeichen im data-Attribut).'
        );
    }

    // =========================================================================
    // Gruppe B — Editor-Views binden den Partial ein
    // =========================================================================

    public function test_admin_editor_includes_partial(): void
    {
        $code = $this->read(self::ADMIN_EDITOR_PATH);
        self::assertStringContainsString(
            '_edit_sessions_indicator.php',
            $code,
            'admin/events/editor.php muss den _edit_sessions_indicator '
            . 'Partial einbinden.'
        );
    }

    public function test_organizer_editor_includes_partial(): void
    {
        $code = $this->read(self::ORGANIZER_EDITOR_PATH);
        self::assertStringContainsString(
            '_edit_sessions_indicator.php',
            $code,
            'organizer/events/editor.php muss den '
            . '_edit_sessions_indicator Partial einbinden.'
        );
    }

    public function test_admin_edit_includes_partial(): void
    {
        $code = $this->read(self::ADMIN_EDIT_PATH);
        self::assertStringContainsString(
            '_edit_sessions_indicator.php',
            $code,
            'admin/events/edit.php muss den _edit_sessions_indicator '
            . 'Partial einbinden (Modal-Editor-Seite bekommt das '
            . 'gleiche Daten-Anker wie die non-modalen Editoren).'
        );
    }

    // =========================================================================
    // Gruppe C — EditSessionView::toJsonReadyArray Verhalten
    // =========================================================================

    public function test_toJsonReadyArray_filters_viewer_self(): void
    {
        $own = new EditSessionView(
            1, 10, 'Max', 'Mustermann',
            '2026-04-24 12:00:00', '2026-04-24 12:03:00', 180
        );
        $other = new EditSessionView(
            2, 20, 'Anna', 'Schmidt',
            '2026-04-24 12:00:30', '2026-04-24 12:03:00', 150
        );

        $result = EditSessionView::toJsonReadyArray([$own, $other], 10);
        self::assertCount(1, $result);
        self::assertSame(20, $result[0]['user_id']);
        self::assertSame('Anna Schmidt', $result[0]['display_name']);
    }

    public function test_toJsonReadyArray_deduplicates_per_user_id(): void
    {
        // Multi-Tab-Szenario: zwei Sessions desselben Users, aber
        // verschiedene browser_session_ids. Anzeige soll nur einen
        // Eintrag zeigen.
        $tab1 = new EditSessionView(
            1, 20, 'Anna', 'Schmidt',
            '2026-04-24 12:00:00', '2026-04-24 12:03:00', 180
        );
        $tab2 = new EditSessionView(
            2, 20, 'Anna', 'Schmidt',
            '2026-04-24 12:01:00', '2026-04-24 12:03:00', 120
        );

        $result = EditSessionView::toJsonReadyArray([$tab1, $tab2], 10);
        self::assertCount(
            1,
            $result,
            'Multi-Tab desselben Users muss auf einen Eintrag '
            . 'zusammengefasst werden (R2 aus I7e-C G1).'
        );
        self::assertSame(1, $result[0]['id'], 'Erster Eintrag gewinnt.');
    }

    public function test_toJsonReadyArray_returns_empty_on_empty_input(): void
    {
        self::assertSame([], EditSessionView::toJsonReadyArray([], 10));
    }

    public function test_toJsonReadyArray_exposes_expected_keys(): void
    {
        $view = new EditSessionView(
            42, 20, 'Max', 'Mustermann',
            '2026-04-24 12:00:00', '2026-04-24 12:05:00', 300
        );
        $result = EditSessionView::toJsonReadyArray([$view], 10);

        self::assertArrayHasKey('id', $result[0]);
        self::assertArrayHasKey('user_id', $result[0]);
        self::assertArrayHasKey('display_name', $result[0]);
        self::assertArrayHasKey('started_at', $result[0]);
        self::assertArrayHasKey('last_seen_at', $result[0]);
        self::assertArrayHasKey('duration_seconds', $result[0]);

        self::assertSame(42, $result[0]['id']);
        self::assertSame('Max Mustermann', $result[0]['display_name']);
        self::assertSame(300, $result[0]['duration_seconds']);
    }
}
