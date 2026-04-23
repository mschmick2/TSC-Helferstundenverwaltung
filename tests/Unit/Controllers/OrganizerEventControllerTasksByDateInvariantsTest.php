<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Statische Code-Invarianten fuer OrganizerEventController::tasksByDate
 * (Modul 6 I7b4).
 *
 * Die Organizer-Group in src/config/routes.php hat NUR AuthMiddleware +
 * CsrfMiddleware — keine RoleMiddleware. Das heisst jeder angemeldete
 * Nutzer kann prinzipiell /organizer/events/{id}/tasks-by-date aufrufen.
 * Der Owner-Check MUSS darum intern im Controller stattfinden. Diese
 * Tests faengen Regressionen ab, die diese Pruefung entfernen oder
 * falsch platzieren.
 *
 * Runtime-Integration (echte HTTP-Requests + Session) laeuft via
 * Playwright-Spec 13. Die hier gepruefte statische Struktur ist die
 * schnelle erste Verteidigungslinie.
 */
final class OrganizerEventControllerTasksByDateInvariantsTest extends TestCase
{
    private const CONTROLLER_PATH = __DIR__ . '/../../../src/app/Controllers/OrganizerEventController.php';
    private const VIEW_ORGANIZER  = __DIR__ . '/../../../src/app/Views/organizer/events/tasks_by_date.php';

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

    public function test_tasksByDate_action_exists(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'tasksByDate');

        self::assertNotSame(
            '',
            $body,
            'OrganizerEventController::tasksByDate() muss existieren (I7b4).'
        );
    }

    public function test_tasksByDate_guards_on_settingsService_and_aggregator(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'tasksByDate');

        // Der Ctor akzeptiert SettingsService und TaskTreeAggregator als
        // optional (default null). Die Action muss bei null-Injection mit
        // 404 abbrechen, damit Tests und Alt-DI-Config nicht abstuerzen.
        self::assertMatchesRegularExpression(
            '/\$this->settingsService\s*===\s*null/',
            $body,
            'tasksByDate() muss settingsService-null-Fall mit 404 abfangen.'
        );
        self::assertMatchesRegularExpression(
            '/\$this->treeAggregator\s*===\s*null/',
            $body,
            'tasksByDate() muss treeAggregator-null-Fall mit 404 abfangen.'
        );
    }

    public function test_tasksByDate_checks_feature_flag(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'tasksByDate');

        // Flag-Gate: konsistent zu I7b1/I7b2/I7b3. 404 bei Flag=0.
        self::assertMatchesRegularExpression(
            "/events\\.tree_editor_enabled/",
            $body,
            'tasksByDate() muss das Flag events.tree_editor_enabled pruefen.'
        );
        self::assertMatchesRegularExpression(
            '/withStatus\s*\(\s*404\s*\)/',
            $body,
            'tasksByDate() muss bei Flag=0 mit 404 antworten.'
        );
    }

    public function test_tasksByDate_checks_isOrganizer_before_rendering(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'tasksByDate');

        // Owner-Check MUSS im Controller stattfinden, da die Organizer-
        // Group keine RoleMiddleware hat.
        self::assertStringContainsString(
            '->isOrganizer(',
            $body,
            'tasksByDate() muss EventOrganizerRepository::isOrganizer() '
            . 'aufrufen — sonst koennte jeder angemeldete Nutzer fremde '
            . 'Events einsehen.'
        );
        self::assertMatchesRegularExpression(
            '/withStatus\s*\(\s*403\s*\)/',
            $body,
            'Nicht-Organisatoren muessen 403 bekommen (kein 404 — 403 ist '
            . 'praeziser, weil der User angemeldet ist; kein Information-Leak '
            . 'denn die Existenz des Events ist per Event-Liste ohnehin '
            . 'bekannt, wenn der User in anderen Event-Organizer-Rollen ist).'
        );
    }

    public function test_tasksByDate_order_isOrganizer_precedes_findById(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'tasksByDate');

        // Defensive: isOrganizer() muss VOR eventRepo->findById() stehen,
        // damit ein Nicht-Organizer den 403 bekommt, bevor die Event-
        // Existenz geraten werden kann. Verhindert Information-Leak.
        $isOrgPos  = strpos($body, 'isOrganizer(');
        $findIdPos = strpos($body, 'findById(');

        self::assertNotFalse($isOrgPos, 'isOrganizer muss aufgerufen werden.');
        self::assertNotFalse($findIdPos, 'findById muss aufgerufen werden.');
        self::assertLessThan(
            $findIdPos,
            $isOrgPos,
            'isOrganizer() muss VOR findById() gerufen werden (403 vor 404 '
            . 'bei unberechtigtem Zugriff; Information-Leak-Schutz).'
        );
    }

    public function test_tasksByDate_uses_flattenToList_not_buildTree(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'tasksByDate');

        self::assertMatchesRegularExpression(
            '/\$this->treeAggregator->flattenToList\s*\(/',
            $body,
            'tasksByDate() muss flattenToList() aufrufen — nicht '
            . 'buildTree() (das waere die Tree-Ansicht).'
        );
    }

    public function test_tasksByDate_sets_linkTaskTitles_false(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'tasksByDate');

        self::assertMatchesRegularExpression(
            "/'linkTaskTitles'\s*=>\s*false/",
            $body,
            'Organizer-Kontext muss linkTaskTitles=false setzen — '
            . 'Admin-Detail-Seite ist per RoleMiddleware event_admin-gebunden, '
            . 'Organisator bekaeme dort 403 (G1-Entscheidung B).'
        );
    }

    public function test_tasksByDate_sorts_by_start_at(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'tasksByDate');

        self::assertMatchesRegularExpression(
            '/usort\s*\(\s*\$flatList/',
            $body,
            'tasksByDate() muss flatList nach start_at sortieren (chronologisch).'
        );
    }

    public function test_tasksByDate_renders_organizer_container_view(): void
    {
        $code = $this->read(self::CONTROLLER_PATH);
        $body = $this->methodBody($code, 'tasksByDate');

        self::assertStringContainsString(
            "'organizer/events/tasks_by_date'",
            $body,
            'tasksByDate() rendert die Organizer-Container-View '
            . '(organizer/events/tasks_by_date.php).'
        );
    }

    public function test_organizer_tasks_by_date_view_includes_shared_partial(): void
    {
        $view = $this->read(self::VIEW_ORGANIZER);

        self::assertStringContainsString(
            "include __DIR__ . '/../../events/_task_list_by_date.php'",
            $view,
            'organizer/events/tasks_by_date.php muss das gemeinsame Partial '
            . 'events/_task_list_by_date.php einbinden (DRY mit Admin-View).'
        );
    }
}
