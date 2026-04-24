<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invariants fuer die Optimistic-Lock-Verkabelung durch die
 * Controller-/View-/JS-Schichten (Modul 6 I7e-B.1 Phase 2).
 *
 * Phase 1 hatte Service + Repo. Diese Tests sichern, dass Phase 2
 * den Pfad Ende-zu-Ende verbunden hat:
 *
 *   1. Die 4 mutierenden Event-Actions (pro Controller) lesen
 *      `version` aus $parsedBody, reichen `$expectedVersion` an
 *      den Service und fangen `OptimisticLockException` mit
 *      `lockConflictResponse`.
 *   2. `editTaskNode` liefert `version` im JSON-Body, damit das
 *      JS das Hidden-Field rendern kann.
 *   3. `TreeActionHelpers::lockConflictResponse` existiert und
 *      gibt bei JSON-Clients 409 mit `optimistic_lock_conflict`
 *      zurueck.
 *   4. `EventTreeActionHelpers::serializeTreeForJson` liefert
 *      `version` pro Knoten (fuer JS-Refresh-Pfade).
 *   5. `_task_tree_node.php` rendert `data-task-version`.
 *   6. `event-task-tree.js` rendert ein Hidden-Field `version`
 *      im Edit-Modal-Form, liest `data-task-version` vor Move/
 *      Convert/Delete-POSTs, und erkennt
 *      `optimistic_lock_conflict` im Response-Handler.
 *
 * Template-Controller (I7c) bleibt in Phase 2 UNVERAENDERT — der
 * Lock-Pfad wird erst mit Follow-up y aktiviert (event_template_
 * tasks braucht eine version-Spalte, die Migration 007 nicht hat).
 */
final class OptimisticLockControllerWiringTest extends TestCase
{
    private const ADMIN_CONTROLLER =
        __DIR__ . '/../../../src/app/Controllers/EventAdminController.php';
    private const ORGANIZER_CONTROLLER =
        __DIR__ . '/../../../src/app/Controllers/OrganizerEventEditController.php';
    private const TREE_TRAIT =
        __DIR__ . '/../../../src/app/Controllers/Concerns/TreeActionHelpers.php';
    private const EVENT_TREE_TRAIT =
        __DIR__ . '/../../../src/app/Controllers/Concerns/EventTreeActionHelpers.php';
    private const PARTIAL_NODE =
        __DIR__ . '/../../../src/app/Views/admin/events/_task_tree_node.php';
    private const JS_KERN =
        __DIR__ . '/../../../src/public/js/event-task-tree.js';

    /** Die 4 mutierenden Actions je Event-Controller (Phase-2-Lock). */
    private const LOCK_ACTIONS = [
        'moveTaskNode',
        'convertTaskNode',
        'deleteTaskNode',
        'updateTaskNode',
    ];

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
    // Gruppe A — Controller-Actions lesen version + reichen durch + fangen Exception
    // =========================================================================

    public function test_event_actions_read_version_from_parsed_body(): void
    {
        foreach ([self::ADMIN_CONTROLLER, self::ORGANIZER_CONTROLLER] as $path) {
            $code = $this->read($path);
            foreach (self::LOCK_ACTIONS as $action) {
                $body = $this->methodBody($code, $action);
                self::assertNotSame('', $body, basename($path) . "::$action fehlt.");
                self::assertMatchesRegularExpression(
                    "/\\\$expectedVersion\\s*=\\s*isset\\(\\s*\\\$data\\[\\s*'version'\\s*\\]\\s*\\)/",
                    $body,
                    basename($path) . "::$action muss \$expectedVersion aus "
                    . "\$data['version'] lesen (isset-Guard + int-Cast)."
                );
            }
        }
    }

    public function test_event_actions_pass_expected_version_to_service(): void
    {
        foreach ([self::ADMIN_CONTROLLER, self::ORGANIZER_CONTROLLER] as $path) {
            $code = $this->read($path);
            foreach (self::LOCK_ACTIONS as $action) {
                $body = $this->methodBody($code, $action);
                self::assertStringContainsString(
                    '$expectedVersion',
                    $body,
                    basename($path) . "::$action muss \$expectedVersion "
                    . "definieren UND an den Service weiterreichen."
                );
                // Mindestens ein Service-Call mit $expectedVersion als Argument.
                self::assertMatchesRegularExpression(
                    '/\$this->treeService->\w+\([^)]*\$expectedVersion\s*\)/s',
                    $body,
                    basename($path) . "::$action muss \$expectedVersion an "
                    . "einen \$this->treeService->...-Call weitergeben."
                );
            }
        }
    }

    public function test_event_actions_catch_optimistic_lock_exception(): void
    {
        foreach ([self::ADMIN_CONTROLLER, self::ORGANIZER_CONTROLLER] as $path) {
            $code = $this->read($path);
            foreach (self::LOCK_ACTIONS as $action) {
                $body = $this->methodBody($code, $action);
                self::assertMatchesRegularExpression(
                    '/catch\s*\(\s*OptimisticLockException\s+\$\w+\s*\)/',
                    $body,
                    basename($path) . "::$action muss OptimisticLockException "
                    . "fangen."
                );
                self::assertStringContainsString(
                    '$this->lockConflictResponse(',
                    $body,
                    basename($path) . "::$action muss lockConflictResponse() "
                    . "im catch-Block aufrufen."
                );
            }
        }
    }

    public function test_event_controllers_import_optimistic_lock_exception(): void
    {
        foreach ([self::ADMIN_CONTROLLER, self::ORGANIZER_CONTROLLER] as $path) {
            $code = $this->read($path);
            self::assertStringContainsString(
                'use App\\Exceptions\\OptimisticLockException;',
                $code,
                basename($path) . ' muss OptimisticLockException importieren.'
            );
        }
    }

    // =========================================================================
    // Gruppe B — editTaskNode liefert version im JSON
    // =========================================================================

    public function test_editTaskNode_returns_version_in_task_json(): void
    {
        foreach ([self::ADMIN_CONTROLLER, self::ORGANIZER_CONTROLLER] as $path) {
            $body = $this->methodBody($this->read($path), 'editTaskNode');
            self::assertMatchesRegularExpression(
                "/'version'\\s*=>\\s*\\\$task->getVersion\\(\\)/",
                $body,
                basename($path) . "::editTaskNode muss 'version' => "
                . "\$task->getVersion() im JSON-Task-Array liefern."
            );
        }
    }

    // =========================================================================
    // Gruppe C — TreeActionHelpers::lockConflictResponse
    // =========================================================================

    public function test_trait_has_lock_conflict_response_method(): void
    {
        $code = $this->read(self::TREE_TRAIT);
        self::assertMatchesRegularExpression(
            '/protected\s+function\s+lockConflictResponse\s*\(/',
            $code,
            'TreeActionHelpers muss eine protected-Methode '
            . 'lockConflictResponse haben.'
        );
    }

    public function test_lock_conflict_response_returns_409_with_marker(): void
    {
        $body = $this->methodBody($this->read(self::TREE_TRAIT), 'lockConflictResponse');
        self::assertNotSame('', $body, 'lockConflictResponse fehlt.');
        self::assertStringContainsString(
            "'optimistic_lock_conflict'",
            $body,
            'lockConflictResponse muss den Marker-String '
            . "'optimistic_lock_conflict' im JSON-Body setzen "
            . '(JS pruefft errorCode === "optimistic_lock_conflict").'
        );
        self::assertMatchesRegularExpression(
            '/\b409\b/',
            $body,
            'lockConflictResponse muss HTTP-Status 409 nutzen.'
        );
        self::assertStringContainsString(
            '$this->wantsJson($request)',
            $body,
            'lockConflictResponse muss per wantsJson zwischen JSON- '
            . 'und Flash-Redirect-Pfad unterscheiden.'
        );
    }

    // =========================================================================
    // Gruppe D — serializeTreeForJson gibt version mit
    // =========================================================================

    public function test_serialize_tree_for_json_includes_version(): void
    {
        $body = $this->methodBody($this->read(self::EVENT_TREE_TRAIT), 'serializeTreeForJson');
        self::assertNotSame('', $body, 'serializeTreeForJson fehlt im Trait.');
        self::assertMatchesRegularExpression(
            "/'version'\\s*=>\\s*\\\$task->getVersion\\(\\)/",
            $body,
            'EventTreeActionHelpers::serializeTreeForJson muss '
            . 'version pro Knoten an JS liefern, damit ein spaeterer '
            . 'JS-Refresh-Pfad (ohne full Page-Reload) den aktuellen '
            . 'Lock-Token ins DOM schreiben kann.'
        );
    }

    // =========================================================================
    // Gruppe E — _task_tree_node.php rendert data-task-version
    // =========================================================================

    public function test_partial_renders_data_task_version_attribute(): void
    {
        $code = $this->read(self::PARTIAL_NODE);
        self::assertStringContainsString(
            'data-task-version=',
            $code,
            '_task_tree_node.php muss data-task-version am <li> rendern, '
            . 'damit das JS den Lock-Token fuer Move/Convert/Delete-POSTs '
            . 'lesen kann.'
        );
    }

    // =========================================================================
    // Gruppe F — event-task-tree.js
    // =========================================================================

    public function test_js_renders_hidden_version_input_in_edit_form(): void
    {
        $js = $this->read(self::JS_KERN);
        // buildForm hinterlegt <input type="hidden" name="version" value="...">
        // bei Edit-Modus. Die Konstruktion ueber DOM-APIs, nicht innerHTML.
        self::assertMatchesRegularExpression(
            "/versionInput\\.name\\s*=\\s*'version'/",
            $js,
            'buildForm muss ein verstecktes <input name="version"> '
            . 'ins Edit-Formular einfuegen.'
        );
    }

    public function test_js_has_read_task_version_helper(): void
    {
        $js = $this->read(self::JS_KERN);
        self::assertMatchesRegularExpression(
            '/function\s+readTaskVersion\s*\(/',
            $js,
            'event-task-tree.js muss eine readTaskVersion(nodeLi)-Hilfe '
            . 'definieren, die data-task-version vom <li> liest.'
        );
        self::assertStringContainsString(
            'dataset.taskVersion',
            $js,
            'readTaskVersion muss via nodeLi.dataset.taskVersion auf '
            . 'data-task-version zugreifen.'
        );
    }

    public function test_js_move_convert_delete_send_version(): void
    {
        $js = $this->read(self::JS_KERN);
        // handleSortEnd sendet version: readTaskVersion(item).
        self::assertMatchesRegularExpression(
            '/version:\s*readTaskVersion\(\s*item\s*\)/',
            $js,
            'handleSortEnd muss version via readTaskVersion(item) an '
            . 'die move-Payload anhaengen.'
        );
        // handleConvert und handleDelete senden version: readTaskVersion(nodeLi).
        self::assertMatchesRegularExpression(
            '/version:\s*readTaskVersion\(\s*nodeLi\s*\)/',
            $js,
            'handleConvert und handleDelete muessen version via '
            . 'readTaskVersion(nodeLi) an die Payload anhaengen.'
        );
    }

    public function test_js_handles_lock_conflict_marker(): void
    {
        $js = $this->read(self::JS_KERN);
        self::assertMatchesRegularExpression(
            '/function\s+handleLockConflict\s*\(/',
            $js,
            'event-task-tree.js muss handleLockConflict(result) '
            . 'definieren.'
        );
        self::assertStringContainsString(
            "'optimistic_lock_conflict'",
            $js,
            'event-task-tree.js muss den Marker-String '
            . "'optimistic_lock_conflict' in den Response-Handlern "
            . 'pruefen (errorCode aus postJson).'
        );
        // Die Handler fuer move, convert, delete, save muessen den Marker
        // erkennen und handleLockConflict aufrufen. Mind. drei Aufrufe
        // im File erwartet (save + mind. zwei der drei Mutations-Handler).
        $calls = preg_match_all('/handleLockConflict\(\s*result\s*\)/', $js);
        self::assertGreaterThanOrEqual(
            3,
            $calls,
            'handleLockConflict muss mindestens dreimal im JS '
            . 'aufgerufen werden (Save + Move + Convert/Delete).'
        );
    }

    public function test_js_post_json_exposes_error_code(): void
    {
        $js = $this->read(self::JS_KERN);
        self::assertMatchesRegularExpression(
            '/errorCode:\s*data\.error\s*\|\|\s*null/',
            $js,
            'postJson muss errorCode aus data.error extrahieren, '
            . 'damit der Client-Code zwischen optimistic_lock_conflict '
            . 'und anderen 409-Faellen unterscheiden kann.'
        );
    }
}
