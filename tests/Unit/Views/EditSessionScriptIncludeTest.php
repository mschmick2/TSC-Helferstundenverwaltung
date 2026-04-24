<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

/**
 * View-Invariants fuer die Script-/Meta-Tag-Integration der Edit-
 * Session-Anzeige (Modul 6 I7e-C.1 Phase 3).
 *
 * Sichert ab:
 *   - Die drei Editor-Views binden /js/edit-session.js ein.
 *   - Das Layout main.php rendert <meta name="current-user-id">,
 *     damit das JS den Viewer beim Filter-Schritt erkennt.
 */
final class EditSessionScriptIncludeTest extends TestCase
{
    private const ADMIN_EDITOR_PATH =
        __DIR__ . '/../../../src/app/Views/admin/events/editor.php';
    private const ORGANIZER_EDITOR_PATH =
        __DIR__ . '/../../../src/app/Views/organizer/events/editor.php';
    private const ADMIN_EDIT_PATH =
        __DIR__ . '/../../../src/app/Views/admin/events/edit.php';
    private const LAYOUT_PATH =
        __DIR__ . '/../../../src/app/Views/layouts/main.php';

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    public function test_admin_editor_includes_edit_session_js(): void
    {
        $code = $this->read(self::ADMIN_EDITOR_PATH);
        self::assertStringContainsString(
            '/js/edit-session.js',
            $code,
            'admin/events/editor.php muss edit-session.js einbinden.'
        );
    }

    public function test_organizer_editor_includes_edit_session_js(): void
    {
        $code = $this->read(self::ORGANIZER_EDITOR_PATH);
        self::assertStringContainsString(
            '/js/edit-session.js',
            $code,
            'organizer/events/editor.php muss edit-session.js einbinden.'
        );
    }

    public function test_admin_edit_modal_includes_edit_session_js(): void
    {
        $code = $this->read(self::ADMIN_EDIT_PATH);
        self::assertStringContainsString(
            '/js/edit-session.js',
            $code,
            'admin/events/edit.php (Modal-Editor) muss edit-session.js '
            . 'einbinden.'
        );
    }

    public function test_layout_renders_current_user_id_meta(): void
    {
        $code = $this->read(self::LAYOUT_PATH);
        self::assertStringContainsString(
            'name="current-user-id"',
            $code,
            'Layout main.php muss <meta name="current-user-id"> '
            . 'rendern, damit edit-session.js den Viewer beim Filter-'
            . 'Schritt erkennt.'
        );
    }

    public function test_layout_renders_csrf_token_meta(): void
    {
        // Bestand-Schutz: das CSRF-Meta darf nicht versehentlich
        // entfernt werden — edit-session.js (und event-task-tree.js)
        // braucht es als CSRF-Quelle fuer die fetch-Calls.
        $code = $this->read(self::LAYOUT_PATH);
        self::assertStringContainsString(
            'name="csrf-token"',
            $code,
            'Layout main.php muss <meta name="csrf-token"> rendern.'
        );
    }

    public function test_edit_session_js_loaded_after_event_task_tree_js(): void
    {
        // Reihenfolge ist nicht semantisch zwingend (beide Skripte
        // initialisieren sich im DOMContentLoaded-Listener und stoeren
        // sich nicht), aber die Konsistenz ueber alle drei Editor-
        // Views hilft beim Lesen.
        foreach ([self::ADMIN_EDITOR_PATH, self::ORGANIZER_EDITOR_PATH, self::ADMIN_EDIT_PATH] as $path) {
            $code = $this->read($path);
            $treePos = strpos($code, '/js/event-task-tree.js');
            $sessPos = strpos($code, '/js/edit-session.js');
            self::assertNotFalse($treePos, basename($path) . ': event-task-tree.js fehlt.');
            self::assertNotFalse($sessPos, basename($path) . ': edit-session.js fehlt.');
            self::assertLessThan(
                $sessPos,
                $treePos,
                basename($path) . ': edit-session.js muss NACH '
                . 'event-task-tree.js eingebunden werden (Konvention).'
            );
        }
    }
}
