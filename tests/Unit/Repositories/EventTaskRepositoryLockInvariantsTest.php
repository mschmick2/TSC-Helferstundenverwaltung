<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invariants fuer den Optimistic-Lock-Pfad auf
 * Repository-Ebene (Modul 6 I7e-B.1 Phase 1).
 *
 * Sichert ab:
 *   - Die fuenf mutierenden Repo-Methoden (update, move, convertToGroup,
 *     convertToLeaf, softDelete) akzeptieren `?int $expectedVersion = null`.
 *   - Das SQL fuehrt `AND version = :version` an den WHERE-Teil an, wenn
 *     der Parameter gesetzt ist.
 *   - Der `:version`-Bind-Wert wird nur bei gesetztem Parameter gebunden
 *     (sonst wuerde MySQL ueber einen leeren Bind klagen).
 *   - `version = version + 1` bleibt im SET-Teil bestehen (monoton
 *     steigender Counter, konsistent zu Modul 7 I3).
 *   - reorderSiblings hat KEINEN expectedVersion-Parameter (G1-B5).
 */
final class EventTaskRepositoryLockInvariantsTest extends TestCase
{
    private const REPO_PATH =
        __DIR__ . '/../../../src/app/Repositories/EventTaskRepository.php';

    private const METHODS_WITH_LOCK = [
        'update',
        'move',
        'convertToGroup',
        'convertToLeaf',
        'softDelete',
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

    private function methodSignature(string $code, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\(([^)]*)\)/s';
        if (preg_match($pattern, $code, $m)) {
            return $m[1];
        }
        return '';
    }

    // =========================================================================
    // Signatur
    // =========================================================================

    public function test_mutating_methods_accept_expected_version(): void
    {
        $code = $this->read(self::REPO_PATH);
        foreach (self::METHODS_WITH_LOCK as $method) {
            $sig = $this->methodSignature($code, $method);
            self::assertNotSame('', $sig, "Repo-Methode $method() fehlt.");
            self::assertMatchesRegularExpression(
                '/\?int\s+\$expectedVersion\s*=\s*null/',
                $sig,
                "EventTaskRepository::$method() muss als letzten Parameter "
                . "?int \$expectedVersion = null akzeptieren."
            );
        }
    }

    public function test_reorderSiblings_has_no_expected_version_parameter(): void
    {
        $sig = $this->methodSignature($this->read(self::REPO_PATH), 'reorderSiblings');
        self::assertNotSame('', $sig, 'reorderSiblings-Methode fehlt.');
        self::assertStringNotContainsString(
            'expectedVersion',
            $sig,
            'EventTaskRepository::reorderSiblings() darf KEINEN expectedVersion-'
            . 'Parameter haben (G1-B5: Set-Equality-Check im Service ist der '
            . 'implizite Struktur-Lock).'
        );
    }

    // =========================================================================
    // SQL-Pattern: conditional WHERE + conditional Bind + version + 1
    // =========================================================================

    public function test_mutating_methods_append_conditional_version_clause(): void
    {
        $code = $this->read(self::REPO_PATH);
        foreach (self::METHODS_WITH_LOCK as $method) {
            $body = $this->methodBody($code, $method);
            self::assertNotSame('', $body, "$method-Body leer.");
            // SQL-Suffix nur, wenn $expectedVersion gesetzt:
            self::assertMatchesRegularExpression(
                '/if\s*\(\s*\$expectedVersion\s*!==\s*null\s*\)\s*\{/s',
                $body,
                "EventTaskRepository::$method() muss den WHERE-Suffix "
                . "conditional an \$expectedVersion !== null anhaengen."
            );
            self::assertMatchesRegularExpression(
                '/\bAND version = :version\b/',
                $body,
                "EventTaskRepository::$method() muss \"AND version = :version\" "
                . "als Lock-Filter im SQL fuehren."
            );
            // Version-Bind nur im if-Block
            self::assertMatchesRegularExpression(
                "/\\\$params\\s*\\[\\s*'version'\\s*\\]\\s*=\\s*\\\$expectedVersion/",
                $body,
                "EventTaskRepository::$method() muss \$params['version'] = "
                . "\$expectedVersion binden (nur im conditional Block)."
            );
        }
    }

    public function test_mutating_methods_increment_version_counter(): void
    {
        $code = $this->read(self::REPO_PATH);
        foreach (self::METHODS_WITH_LOCK as $method) {
            $body = $this->methodBody($code, $method);
            self::assertMatchesRegularExpression(
                '/version\s*=\s*version\s*\+\s*1/',
                $body,
                "EventTaskRepository::$method() muss version = version + 1 "
                . "im SET-Teil enthalten (monoton steigender Counter, "
                . "Konsistenz zu Modul 7 I3)."
            );
        }
    }

    public function test_mutating_methods_return_bool(): void
    {
        $code = $this->read(self::REPO_PATH);
        foreach (self::METHODS_WITH_LOCK as $method) {
            $sig = $this->methodSignature($code, $method);
            // Return-Type am Method-Head auslesen: '...): bool'
            $pattern = '/function\s+' . preg_quote($method, '/')
                . '\s*\([^)]*\)\s*:\s*bool\b/s';
            self::assertMatchesRegularExpression(
                $pattern,
                $code,
                "EventTaskRepository::$method() muss `: bool` zurueckgeben "
                . "(Service faengt false ab und wirft OptimisticLockException)."
            );
        }
    }
}
