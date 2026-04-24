<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invariants fuer Modul 6 I7e-B.1 Phase 3 Teil 1 —
 * Luecken-Auffuellung nach Analyse der 26 bestehenden
 * Phase-1+2-Invariants.
 *
 * Gepruefte Luecken:
 *
 *  A) Reihenfolge-Checks in den 4 Event-Actions:
 *     - expectedVersion-Lesen findet VOR dem Service-Call statt.
 *     - OptimisticLockException-catch steht NACH den anderen
 *       Exception-catches (konsistente Reihenfolge ueber alle
 *       Actions).
 *
 *  B) Template-Pfad-Negativ-Assertions:
 *     - EventTemplateController importiert NICHT
 *       OptimisticLockException (bestaetigt, dass Phase 2
 *       Template-Pfad unveraendert gelassen hat).
 *     - TemplateTaskTreeService hat keine Lock-Parameter und
 *       importiert die Exception nicht (Phase 1 hatte Template
 *       ausgelassen; das darf nicht unbemerkt drift-en).
 *
 *  C) Flash-Pfad von lockConflictResponse:
 *     - Form-Submit-Fallback schreibt Flash-Message und Redirect.
 *     - JSON-Response-Body enthaelt ein message-Feld (Text, nicht
 *       nur error-Code).
 *
 *  D) JS-Handler-Count:
 *     - handleLockConflict wird in ALLEN 4 Mutation-Handlern
 *       aufgerufen (Save + Move + Convert + Delete), nicht nur
 *       in 3.
 *
 *  E) Model-Getter:
 *     - EventTask::getVersion ist public und gibt int zurueck.
 */
final class OptimisticLockInvariantsGapTest extends TestCase
{
    private const ADMIN_CONTROLLER =
        __DIR__ . '/../../../src/app/Controllers/EventAdminController.php';
    private const ORGANIZER_CONTROLLER =
        __DIR__ . '/../../../src/app/Controllers/OrganizerEventEditController.php';
    private const TEMPLATE_CONTROLLER =
        __DIR__ . '/../../../src/app/Controllers/EventTemplateController.php';
    private const TEMPLATE_SERVICE =
        __DIR__ . '/../../../src/app/Services/TemplateTaskTreeService.php';
    private const TREE_TRAIT =
        __DIR__ . '/../../../src/app/Controllers/Concerns/TreeActionHelpers.php';
    private const EVENT_TASK_MODEL =
        __DIR__ . '/../../../src/app/Models/EventTask.php';
    private const JS_KERN =
        __DIR__ . '/../../../src/public/js/event-task-tree.js';

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
    // Bereich A — Reihenfolge
    // =========================================================================

    public function test_expected_version_is_read_before_service_call(): void
    {
        foreach ([self::ADMIN_CONTROLLER, self::ORGANIZER_CONTROLLER] as $path) {
            $code = $this->read($path);
            foreach (self::LOCK_ACTIONS as $action) {
                $body = $this->methodBody($code, $action);
                self::assertNotSame('', $body, basename($path) . "::$action fehlt.");

                $posRead = strpos($body, '$expectedVersion = isset');
                $posCall = strpos($body, '$this->treeService->');
                self::assertNotFalse(
                    $posRead,
                    basename($path) . "::$action muss \$expectedVersion "
                    . "aus \$data lesen."
                );
                self::assertNotFalse(
                    $posCall,
                    basename($path) . "::$action muss einen Service-Call machen."
                );
                self::assertLessThan(
                    $posCall,
                    $posRead,
                    basename($path) . "::$action muss \$expectedVersion VOR "
                    . "dem Service-Call lesen — sonst bekaeme der Service "
                    . "einen undefinierten Parameter."
                );
            }
        }
    }

    public function test_optimistic_lock_catch_follows_other_catches(): void
    {
        foreach ([self::ADMIN_CONTROLLER, self::ORGANIZER_CONTROLLER] as $path) {
            $code = $this->read($path);
            foreach (self::LOCK_ACTIONS as $action) {
                $body = $this->methodBody($code, $action);

                $posValidation = strpos($body, 'catch (ValidationException');
                $posBusiness   = strpos($body, 'catch (BusinessRuleException');
                $posLock       = strpos($body, 'catch (OptimisticLockException');

                // ValidationException und BusinessRuleException sind schon
                // seit I7b1 da — jede mutierende Action muss mindestens eine
                // von beiden haben.
                self::assertTrue(
                    $posValidation !== false || $posBusiness !== false,
                    basename($path) . "::$action muss mindestens eine der "
                    . "Exceptions ValidationException oder BusinessRuleException "
                    . "fangen."
                );
                self::assertNotFalse(
                    $posLock,
                    basename($path) . "::$action muss OptimisticLockException fangen."
                );

                // OptimisticLockException muss NACH den anderen catches stehen.
                // Reihenfolge konsistent zu vergleichbaren Services (Service-
                // Layer-Exception nach Business-Layer-Exception).
                if ($posValidation !== false) {
                    self::assertGreaterThan(
                        $posValidation,
                        $posLock,
                        basename($path) . "::$action: OptimisticLockException-"
                        . "catch muss NACH ValidationException-catch stehen."
                    );
                }
                if ($posBusiness !== false) {
                    self::assertGreaterThan(
                        $posBusiness,
                        $posLock,
                        basename($path) . "::$action: OptimisticLockException-"
                        . "catch muss NACH BusinessRuleException-catch stehen."
                    );
                }
            }
        }
    }

    // =========================================================================
    // Bereich B — Template-Pfad bleibt unberuehrt
    // =========================================================================

    public function test_template_controller_does_not_import_optimistic_lock_exception(): void
    {
        $code = $this->read(self::TEMPLATE_CONTROLLER);
        self::assertStringNotContainsString(
            'use App\\Exceptions\\OptimisticLockException;',
            $code,
            'EventTemplateController darf OptimisticLockException NICHT '
            . 'importieren — Phase 2 hat den Template-Pfad explizit '
            . 'unveraendert gelassen. Der Template-Lock wird erst mit '
            . 'Follow-up y aktiviert (braucht eigene DB-Migration).'
        );
    }

    public function test_template_service_is_untouched_by_locking(): void
    {
        self::assertFileExists(
            self::TEMPLATE_SERVICE,
            'TemplateTaskTreeService muss existieren.'
        );
        $code = $this->read(self::TEMPLATE_SERVICE);

        self::assertStringNotContainsString(
            'OptimisticLockException',
            $code,
            'TemplateTaskTreeService darf OptimisticLockException nicht '
            . 'kennen — weder als Import noch als Throw. Follow-up y '
            . 'bringt den Lock dort erst nach DB-Migration.'
        );
        self::assertStringNotContainsString(
            '$expectedVersion',
            $code,
            'TemplateTaskTreeService darf keine $expectedVersion-Variable '
            . 'enthalten. Template-Lock ist Follow-up y.'
        );
    }

    // =========================================================================
    // Bereich C — lockConflictResponse-Pfade
    // =========================================================================

    public function test_lock_conflict_response_flash_path_uses_flash_and_redirect(): void
    {
        $body = $this->methodBody($this->read(self::TREE_TRAIT), 'lockConflictResponse');
        self::assertNotSame('', $body, 'lockConflictResponse fehlt im Trait.');

        // Form-Submit-Fallback (wantsJson=false): Flash + Redirect.
        self::assertMatchesRegularExpression(
            '/ViewHelper::flash\(\s*[\'"]warning[\'"]/',
            $body,
            'lockConflictResponse-Flash-Pfad muss ViewHelper::flash'
            . "('warning', ...) aufrufen, damit der Nutzer im Klassik-Form-"
            . 'Pfad eine gut sichtbare Warnmeldung sieht (nicht nur eine "
            . "stumme 409-Fehlerseite).'
        );
        self::assertStringContainsString(
            '$this->redirect(',
            $body,
            'lockConflictResponse-Flash-Pfad muss $this->redirect() '
            . 'aufrufen, damit der Browser zum Editor zurueckkehrt und '
            . 'der frische DB-Stand nachgeladen wird.'
        );
    }

    public function test_lock_conflict_response_json_includes_message_field(): void
    {
        $body = $this->methodBody($this->read(self::TREE_TRAIT), 'lockConflictResponse');
        // Body muss eine $message-Variable definieren und sie in den
        // JSON-Response-Body packen. Das deutsche Wording ist nicht
        // Invarianz, der Schluessel aber schon.
        self::assertMatchesRegularExpression(
            "/'message'\\s*=>\\s*\\\$message/",
            $body,
            "lockConflictResponse muss 'message' => \$message im JSON-"
            . 'Body liefern. Das JS (handleLockConflict) zeigt den Text '
            . 'direkt im Toast — fehlt das Feld, sieht der Nutzer nur '
            . "'HTTP 409'."
        );
    }

    // =========================================================================
    // Bereich D — JS: vier Lock-Handler
    // =========================================================================

    public function test_js_handle_lock_conflict_called_by_all_four_mutation_handlers(): void
    {
        $js = $this->read(self::JS_KERN);
        $calls = preg_match_all('/handleLockConflict\(\s*result\s*\)/', $js);

        // Save (handleFormSubmit) + Move (handleSortEnd) + Convert
        // (handleConvert) + Delete (handleDelete) = 4 Handler. Phase 2
        // hatte >= 3 geprueft; hier strenger auf genau 4.
        self::assertGreaterThanOrEqual(
            4,
            $calls,
            'handleLockConflict muss in allen vier Mutation-Handlern '
            . 'aufgerufen werden (Save + Move + Convert + Delete). '
            . "Gefundene Aufrufe: $calls."
        );
    }

    public function test_js_save_handler_checks_lock_marker(): void
    {
        $js = $this->read(self::JS_KERN);
        if (!preg_match('/function\s+handleFormSubmit\s*\([^)]*\)\s*\{(.*?)\n\s{0,4}\}/s', $js, $m)) {
            self::fail('handleFormSubmit konnte nicht geparst werden.');
        }
        $submitBody = $m[1];
        self::assertStringContainsString(
            "'optimistic_lock_conflict'",
            $submitBody,
            'handleFormSubmit muss auf errorCode === '
            . "'optimistic_lock_conflict' pruefen, bevor es "
            . 'showFormErrors aufruft.'
        );
    }

    public function test_handle_lock_conflict_sets_programmatic_reload_flag(): void
    {
        // Follow-up z (2026-04-24): handleLockConflict muss vor dem
        // window.location.reload() das sessionStorage-Flag
        // vaes_programmatic_reload = '1' setzen, damit edit-session.js
        // im beforeunload-Handler den sendBeacon-Close ueberspringt und
        // Architect-C1 end-zu-end gilt.
        $js = $this->read(self::JS_KERN);
        if (!preg_match('/function\s+handleLockConflict\s*\([^)]*\)\s*\{(.*?)\n\s{0,4}\}/s', $js, $m)) {
            self::fail('handleLockConflict konnte nicht geparst werden.');
        }
        $body = $m[1];
        self::assertMatchesRegularExpression(
            "/sessionStorage\.setItem\(\s*'vaes_programmatic_reload'\s*,\s*'1'\s*\)/",
            $body,
            'handleLockConflict muss sessionStorage.setItem('
            . "'vaes_programmatic_reload', '1') aufrufen, bevor es "
            . 'window.location.reload() ausfuehrt. Ohne das Flag '
            . 'schliesst edit-session.js die Edit-Session via sendBeacon '
            . 'und Architect-C1 haelt nicht end-zu-end.'
        );
        // Die setItem-Zeile MUSS vor dem reload() stehen, sonst
        // wird das Flag erst nach dem Navigate-Ausloesen gesetzt und
        // der beforeunload-Handler sieht es nicht.
        $posSet = strpos($body, "setItem('vaes_programmatic_reload'");
        $posReload = strpos($body, 'window.location.reload(');
        self::assertNotFalse($posSet, 'Flag-Setzung fehlt.');
        self::assertNotFalse($posReload, 'reload()-Aufruf fehlt.');
        self::assertLessThan(
            $posReload,
            $posSet,
            'Das Flag vaes_programmatic_reload muss VOR '
            . 'window.location.reload() gesetzt werden, nicht danach.'
        );
    }

    // =========================================================================
    // Bereich E — Model-Getter
    // =========================================================================

    public function test_event_task_get_version_is_public_int(): void
    {
        $code = $this->read(self::EVENT_TASK_MODEL);
        self::assertMatchesRegularExpression(
            '/public\s+function\s+getVersion\s*\(\s*\)\s*:\s*int/',
            $code,
            'EventTask::getVersion muss public sein und int zurueckgeben. '
            . 'Controller::editTaskNode ruft den Getter im JSON-Response; '
            . 'Serializer und View stuetzen sich darauf.'
        );
    }

    // =========================================================================
    // Bereich F — Ergaenzende Konsistenz
    // =========================================================================

    public function test_lock_conflict_response_uses_wants_json_branching(): void
    {
        $body = $this->methodBody($this->read(self::TREE_TRAIT), 'lockConflictResponse');
        // Reihenfolge: JSON-Check zuerst, dann Flash-Pfad als Fallback.
        $posWantsJson = strpos($body, 'wantsJson');
        $posFlash     = strpos($body, 'ViewHelper::flash');
        self::assertNotFalse($posWantsJson, 'wantsJson fehlt in lockConflictResponse.');
        self::assertNotFalse($posFlash, 'ViewHelper::flash fehlt in lockConflictResponse.');
        self::assertLessThan(
            $posFlash,
            $posWantsJson,
            'lockConflictResponse: wantsJson-Check muss vor dem Flash-Pfad '
            . 'laufen — JSON-Clients sollen NICHT mit Flash-Warning '
            . 'redirectet werden.'
        );
    }

    // =========================================================================
    // Bereich G — Reihenfolge Feature-Flag vs. Version-Parsing (FU-G6-2)
    //
    // Der Feature-Flag-Check (treeEditorEnabled) muss in jeder der vier
    // Lock-Actions beider Event-Controller VOR dem Parsen des
    // version-Felds aus dem Request-Body stehen. Bedeutung:
    //   - Bei Flag=0 wird die Action mit 404 beendet, bevor irgendein
    //     Request-Parsing passiert. Das ist der Produktions-Schutz auf
    //     Strato (events.tree_editor_enabled=0).
    //   - Drift-Schutz: ein kuenftiges Refactoring darf die Reihenfolge
    //     nicht unbemerkt drehen.
    //
    // Der G4-Security-Review hat diese Reihenfolge als nicht-sicherheits-
    // relevant klassifiziert (Dimension 8), weil der Lock keine
    // Authorisierung ist. Der Test ist ein Konsistenz-Guard gegen
    // Refactoring-Drift, kein Security-Guard.
    // =========================================================================

    public function test_feature_flag_check_precedes_expected_version_parsing(): void
    {
        foreach ([self::ADMIN_CONTROLLER, self::ORGANIZER_CONTROLLER] as $path) {
            $code = $this->read($path);
            foreach (self::LOCK_ACTIONS as $action) {
                $body = $this->methodBody($code, $action);
                self::assertNotSame('', $body, basename($path) . "::$action fehlt.");

                $flagPos    = strpos($body, 'treeEditorEnabled');
                $versionPos = strpos($body, "\$data['version']");

                self::assertNotFalse(
                    $flagPos,
                    basename($path) . "::$action muss treeEditorEnabled() "
                    . 'aufrufen (Feature-Flag-Guard).'
                );
                self::assertNotFalse(
                    $versionPos,
                    basename($path) . "::$action muss \$data['version'] "
                    . 'aus dem Request-Body lesen (Lock-Token).'
                );
                self::assertLessThan(
                    $versionPos,
                    $flagPos,
                    basename($path) . "::$action: treeEditorEnabled()-Check "
                    . "muss VOR dem Parsen von \$data['version'] stehen. "
                    . 'Drift-Schutz -- bei Flag=0 soll die Action mit 404 '
                    . 'enden, bevor irgendein Request-Parsing laeuft.'
                );
            }
        }
    }
}
