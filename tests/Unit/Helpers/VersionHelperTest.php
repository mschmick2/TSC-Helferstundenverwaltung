<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\VersionHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für VersionHelper
 */
class VersionHelperTest extends TestCase
{
    private string $versionFile;

    protected function setUp(): void
    {
        // Pfad zur version.json relativ zum Helper
        $this->versionFile = __DIR__ . '/../../../src/storage/version.json';

        // Static Cache zurücksetzen via Reflection
        $reflection = new \ReflectionClass(VersionHelper::class);
        $prop = $reflection->getProperty('versionInfo');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function tearDown(): void
    {
        // Test-Datei aufräumen falls erstellt
        if (file_exists($this->versionFile) && str_contains(file_get_contents($this->versionFile), 'test')) {
            unlink($this->versionFile);
        }

        // Static Cache zurücksetzen
        $reflection = new \ReflectionClass(VersionHelper::class);
        $prop = $reflection->getProperty('versionInfo');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // =========================================================================
    // Versionsstring-Format
    // =========================================================================

    /** @test */
    public function version_string_enthaelt_prefix_und_version(): void
    {
        $result = VersionHelper::getVersionString('1.3.0');

        $this->assertStringStartsWith('Vereins-Arbeitsstunden-Erfassungssystem v1.3.0', $result);
    }

    /** @test */
    public function version_string_mit_version_json(): void
    {
        // version.json mit Testdaten erstellen
        $dir = dirname($this->versionFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->versionFile, json_encode([
            'hash' => 'abc123f',
            'date' => '2025-02-09',
        ]));

        $result = VersionHelper::getVersionString('1.3.0');

        $this->assertSame('Vereins-Arbeitsstunden-Erfassungssystem v1.3.0 (2025-02-09) [abc123f]', $result);

        // Aufräumen
        unlink($this->versionFile);
    }

    /** @test */
    public function version_string_ignoriert_ungueltigen_hash(): void
    {
        $dir = dirname($this->versionFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->versionFile, json_encode([
            'hash' => '<script>test</script>',
            'date' => '2025-02-09',
        ]));

        $result = VersionHelper::getVersionString('1.3.0');

        // Ungültiger Hash wird ignoriert, Datum bleibt
        $this->assertStringContainsString('(2025-02-09)', $result);
        $this->assertStringNotContainsString('script', $result);

        unlink($this->versionFile);
    }

    /** @test */
    public function version_string_ignoriert_ungueltiges_datum(): void
    {
        $dir = dirname($this->versionFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->versionFile, json_encode([
            'hash' => 'abc123f',
            'date' => 'invalid-date',
        ]));

        $result = VersionHelper::getVersionString('1.3.0');

        $this->assertStringNotContainsString('invalid-date', $result);
        $this->assertStringContainsString('[abc123f]', $result);

        unlink($this->versionFile);
    }

    /** @test */
    public function version_string_cached_ergebnis(): void
    {
        // Erster Aufruf
        $result1 = VersionHelper::getVersionString('1.3.0');
        // Zweiter Aufruf (aus Cache)
        $result2 = VersionHelper::getVersionString('1.3.0');

        $this->assertSame($result1, $result2);
    }
}
