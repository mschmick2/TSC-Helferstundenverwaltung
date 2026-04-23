<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invarianten fuer die beiden Editor-Container-Views
 * admin/events/editor.php und organizer/events/editor.php
 * (Modul 6 I7e-A, Follow-up t aus G6 Tester-Review).
 *
 * Beide Views setzen $urlPrefix, den das Partial _task_tree_node.php
 * als data-endpoint-*-Basis verwendet. Ein Copy-Paste-Fehler zwischen
 * den Views (/admin/events/ vs. /organizer/events/) wuerde dazu fuehren,
 * dass das Tree-Editor-JS alle mutierenden Requests auf die falschen
 * Routen schickt. Die Tests hier fangen das statisch.
 */
final class EditorViewInvariantsTest extends TestCase
{
    private const VIEW_ADMIN =
        __DIR__ . '/../../../src/app/Views/admin/events/editor.php';
    private const VIEW_ORGANIZER =
        __DIR__ . '/../../../src/app/Views/organizer/events/editor.php';

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    // =========================================================================
    // URL-Prefix pro Kontext
    // =========================================================================

    public function test_admin_editor_sets_admin_url_prefix(): void
    {
        $code = $this->read(self::VIEW_ADMIN);
        self::assertMatchesRegularExpression(
            "/\\\$urlPrefix\\w*\\s*=\\s*'\\/admin\\/events\\/'/",
            $code,
            'admin/events/editor.php muss den urlPrefix mit "/admin/events/" '
            . 'als String-Literal beginnen, damit data-endpoint-*-Attribute '
            . 'im Partial _task_tree_node.php auf Admin-Routen zeigen.'
        );
    }

    public function test_organizer_editor_sets_organizer_url_prefix(): void
    {
        $code = $this->read(self::VIEW_ORGANIZER);
        self::assertMatchesRegularExpression(
            "/\\\$urlPrefix\\w*\\s*=\\s*'\\/organizer\\/events\\/'/",
            $code,
            'organizer/events/editor.php muss den urlPrefix mit "/organizer/events/" '
            . 'als String-Literal beginnen, damit das Tree-Editor-JS Mutationen an '
            . 'die Organizer-Route schickt (und nicht an /admin, wo der User ggf. '
            . 'kein event_admin ist).'
        );
    }

    public function test_admin_editor_passes_urlPrefix_to_partial(): void
    {
        $code = $this->read(self::VIEW_ADMIN);
        // Die Render-Closure muss $urlPrefix per use(...) oder Kopie
        // weiterreichen, damit das Partial _task_tree_node.php den Override
        // sieht statt die Default-Formel.
        self::assertMatchesRegularExpression(
            '/\$urlPrefix\s*=\s*\$urlPrefixAdmin/',
            $code,
            'admin/events/editor.php muss $urlPrefix in der Render-Closure '
            . 'aus $urlPrefixAdmin setzen, damit das Partial _task_tree_node.php '
            . 'den kontextgerechten Override sieht.'
        );
    }

    public function test_organizer_editor_passes_urlPrefix_to_partial(): void
    {
        $code = $this->read(self::VIEW_ORGANIZER);
        self::assertMatchesRegularExpression(
            '/\$urlPrefix\s*=\s*\$urlPrefixOrganizer/',
            $code,
            'organizer/events/editor.php muss $urlPrefix in der Render-Closure '
            . 'aus $urlPrefixOrganizer setzen.'
        );
    }

    public function test_url_prefix_ends_with_tasks_slash(): void
    {
        // Default-Formel im Partial haengt Endpunkt-Segmente (tree, node,
        // reorder, {taskId}/move, ...) direkt an $urlPrefix. Wenn der
        // Container-Override den /tasks/-Suffix vergisst, landet die Reorder-
        // URL z.B. bei /admin/events/5reorder statt /admin/events/5/tasks/reorder.
        foreach ([self::VIEW_ADMIN, self::VIEW_ORGANIZER] as $path) {
            $code = $this->read($path);
            self::assertMatchesRegularExpression(
                "/\\.\\s*'\\/tasks\\/'\\s*;/",
                $code,
                basename($path) . ': urlPrefix muss mit "/tasks/" enden, weil '
                . 'das Partial _task_tree_node.php die Endpunkt-Segmente direkt '
                . 'anhaengt.'
            );
        }
    }

    // =========================================================================
    // Sidebar-Partial-Einbindung
    // =========================================================================

    public function test_both_views_include_sidebar_partial(): void
    {
        foreach ([self::VIEW_ADMIN, self::VIEW_ORGANIZER] as $path) {
            $code = $this->read($path);
            self::assertStringContainsString(
                '_editor_sidebar.php',
                $code,
                basename($path) . ' muss das geteilte Sidebar-Partial '
                . '_editor_sidebar.php einbinden.'
            );
        }
    }

    public function test_both_views_include_offcanvas_sidebar(): void
    {
        foreach ([self::VIEW_ADMIN, self::VIEW_ORGANIZER] as $path) {
            $code = $this->read($path);
            self::assertStringContainsString(
                'id="editorSidebarOffcanvas"',
                $code,
                basename($path) . ' muss den Offcanvas-Container mit '
                . 'id="editorSidebarOffcanvas" rendern (Mobile-Sidebar).'
            );
        }
    }
}
