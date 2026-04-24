<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invariants fuer den Optimistic-Lock-Pfad in TaskTreeService
 * (Modul 6 I7e-B.1 Phase 1).
 *
 * Pattern wie die bestehenden Invariants-Test-Dateien: Regex/Substring
 * gegen den File-Inhalt. Faengt Regressionen im Lock-Pattern statisch
 * ab, bevor Runtime-Tests noetig sind.
 *
 * Gepruefte Zusicherungen:
 *   - Die fuenf mutierenden Service-Methoden (updateNode, move,
 *     convertToGroup, convertToLeaf, softDeleteNode) akzeptieren
 *     `?int $expectedVersion = null` als optionalen letzten Parameter.
 *   - Jede dieser Methoden reicht den Parameter an die entsprechende
 *     Repo-Methode durch.
 *   - Jede wirft `OptimisticLockException` bei Repo-Rueckgabe `false`
 *     UND Parameter `!== null`.
 *   - createNode und reorderSiblings haben KEINEN expectedVersion-
 *     Parameter (per G1-B5-Entscheidung).
 *   - Der Service importiert `OptimisticLockException`.
 */
final class TaskTreeServiceLockInvariantsTest extends TestCase
{
    private const SERVICE_PATH =
        __DIR__ . '/../../../src/app/Services/TaskTreeService.php';

    /** Fuenf mutierende Methoden, die einen Lock-Parameter haben MUESSEN. */
    private const MUTATING_WITH_LOCK = [
        'updateNode',
        'move',
        'convertToGroup',
        'convertToLeaf',
        'softDeleteNode',
    ];

    /** Methoden, die per G1-Entscheidung KEINEN Lock bekommen. */
    private const MUTATING_WITHOUT_LOCK = [
        'createNode',
        'reorderSiblings',
    ];

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    /**
     * Body einer Methode ab Signatur-Zeile bis zur naechsten Method-Def.
     * Kopie aus TaskTreeServiceInvariantsTest / EventAdminControllerTree
     * InvariantsTest.
     */
    private function methodBody(string $code, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/')
            . '\s*\([^{]*\{(.*?)(?=private function |public function |protected function |\}\s*$)/s';
        if (preg_match($pattern, $code, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Gibt die Signatur-Zeile (zwischen `function <name>(` und dem ersten
     * `)`) zurueck — also die Parameter-Liste als Rohtext. Promoted
     * Parameters gibt es bei Methoden (nicht Ctor) nicht, darum reicht
     * Regex-Extraktion statt File-Wide.
     */
    private function methodSignature(string $code, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\(([^)]*)\)/s';
        if (preg_match($pattern, $code, $m)) {
            return $m[1];
        }
        return '';
    }

    // =========================================================================
    // Gruppe A — Signatur: Lock-Parameter vorhanden / abwesend
    // =========================================================================

    public function test_mutating_methods_accept_expected_version(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        foreach (self::MUTATING_WITH_LOCK as $method) {
            $sig = $this->methodSignature($code, $method);
            self::assertNotSame('', $sig, "Service-Methode $method() fehlt.");
            self::assertMatchesRegularExpression(
                '/\?int\s+\$expectedVersion\s*=\s*null/',
                $sig,
                "TaskTreeService::$method() muss als letzten Parameter "
                . "?int \$expectedVersion = null akzeptieren (Optimistic Lock)."
            );
        }
    }

    public function test_createNode_has_no_expected_version_parameter(): void
    {
        $sig = $this->methodSignature($this->read(self::SERVICE_PATH), 'createNode');
        self::assertNotSame('', $sig, 'createNode-Methode fehlt.');
        self::assertStringNotContainsString(
            'expectedVersion',
            $sig,
            'createNode() darf KEINEN expectedVersion-Parameter haben — es '
            . 'gibt keine bestehende Version zu locken (neue Row bekommt '
            . 'version=1). G1-B5-Entscheidung.'
        );
    }

    public function test_reorderSiblings_has_no_expected_version_parameter(): void
    {
        $sig = $this->methodSignature($this->read(self::SERVICE_PATH), 'reorderSiblings');
        self::assertNotSame('', $sig, 'reorderSiblings-Methode fehlt.');
        self::assertStringNotContainsString(
            'expectedVersion',
            $sig,
            'reorderSiblings() darf KEINEN expectedVersion-Parameter haben — '
            . 'der bestehende Set-Equality-Check ueber findChildren ist der '
            . 'implizite Struktur-Lock. G1-B5-Entscheidung.'
        );
    }

    // =========================================================================
    // Gruppe B — Parameter-Weitergabe an Repo
    // =========================================================================

    public function test_mutating_methods_pass_expected_version_to_repo(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        // Jede der 5 Methoden muss den Parameter an die Repo-Methode durch-
        // reichen. Praezise Zuordnung Method → Repo-Call:
        $expectedRepoCalls = [
            'updateNode'     => 'update',
            'move'           => 'move',
            'convertToGroup' => 'convertToGroup',
            'convertToLeaf'  => 'convertToLeaf',
            'softDeleteNode' => 'softDelete',
        ];
        foreach ($expectedRepoCalls as $method => $repoMethod) {
            $body = $this->methodBody($code, $method);
            self::assertNotSame('', $body, "$method-Body ist leer.");
            self::assertMatchesRegularExpression(
                '/\$this->taskRepo->' . preg_quote($repoMethod, '/')
                    . '\([^)]*\$expectedVersion\s*\)/',
                $body,
                "TaskTreeService::$method() muss taskRepo->$repoMethod() mit "
                . "\$expectedVersion als letztem Argument aufrufen — sonst "
                . "greift der Lock-Filter im SQL nicht."
            );
        }
    }

    // =========================================================================
    // Gruppe C — Exception-Wurf bei Lock-Konflikt
    // =========================================================================

    public function test_mutating_methods_throw_optimistic_lock_exception_on_conflict(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        foreach (self::MUTATING_WITH_LOCK as $method) {
            $body = $this->methodBody($code, $method);
            self::assertMatchesRegularExpression(
                '/throw\s+new\s+OptimisticLockException\s*\(\s*\$taskId\s*,\s*\$expectedVersion\s*\)/',
                $body,
                "TaskTreeService::$method() muss bei Repo-Rueckgabe false UND "
                . "\$expectedVersion !== null eine OptimisticLockException mit "
                . "(\$taskId, \$expectedVersion) werfen."
            );
            self::assertMatchesRegularExpression(
                '/\$expectedVersion\s*!==\s*null/',
                $body,
                "TaskTreeService::$method() muss den Lock-Exception-Wurf an "
                . "\$expectedVersion !== null koppeln — sonst wuerden auch "
                . "Legacy-Aufrufe ohne Lock die Exception bekommen."
            );
        }
    }

    public function test_service_imports_optimistic_lock_exception(): void
    {
        $code = $this->read(self::SERVICE_PATH);
        self::assertStringContainsString(
            'use App\\Exceptions\\OptimisticLockException;',
            $code,
            'TaskTreeService muss OptimisticLockException via use-Statement '
            . 'importieren.'
        );
    }

    // =========================================================================
    // Gruppe D — OptimisticLockException selbst
    // =========================================================================

    public function test_optimistic_lock_exception_exists(): void
    {
        $path = __DIR__ . '/../../../src/app/Exceptions/OptimisticLockException.php';
        self::assertFileExists($path, 'OptimisticLockException-Datei fehlt.');
        $code = (string) file_get_contents($path);

        self::assertStringContainsString(
            'namespace App\\Exceptions;',
            $code,
            'OptimisticLockException muss im Namespace App\\Exceptions liegen.'
        );
        self::assertMatchesRegularExpression(
            '/class\s+OptimisticLockException\s+extends\s+RuntimeException/',
            $code,
            'OptimisticLockException muss RuntimeException erweitern '
            . '(Konsistenz zu BusinessRuleException).'
        );
        self::assertMatchesRegularExpression(
            '/public\s+function\s+getEntityId\s*\(\s*\)\s*:\s*int/',
            $code,
            'OptimisticLockException muss getEntityId(): int anbieten.'
        );
        self::assertMatchesRegularExpression(
            '/public\s+function\s+getExpectedVersion\s*\(\s*\)\s*:\s*int/',
            $code,
            'OptimisticLockException muss getExpectedVersion(): int anbieten.'
        );
    }
}
