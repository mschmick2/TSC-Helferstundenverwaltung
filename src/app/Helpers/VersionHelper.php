<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Hilfsfunktionen für Versionsanzeige (REQ-VER-001-003)
 */
class VersionHelper
{
    private static ?array $versionInfo = null;

    /**
     * Formatierte Versionsanzeige gemäß REQ-VER-003
     *
     * Format: "Vereins-Arbeitsstunden-Erfassungssystem v1.3.0 (2025-02-09) [abc123f]"
     */
    public static function getVersionString(string $version): string
    {
        $info = self::loadVersionInfo();

        $result = "Vereins-Arbeitsstunden-Erfassungssystem v{$version}";

        if ($info['date'] !== '') {
            $result .= " ({$info['date']})";
        }
        if ($info['hash'] !== '') {
            $result .= " [{$info['hash']}]";
        }

        return $result;
    }

    /**
     * Versionsinformationen laden (Git oder Fallback-Datei)
     *
     * @return array{hash: string, date: string}
     */
    private static function loadVersionInfo(): array
    {
        if (self::$versionInfo !== null) {
            return self::$versionInfo;
        }

        // Versuch 1: Aus version.json lesen (Deployment-Artefakt)
        $versionFile = __DIR__ . '/../../storage/version.json';
        if (file_exists($versionFile)) {
            $content = file_get_contents($versionFile);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $rawHash = $data['hash'] ?? '';
                    $rawDate = $data['date'] ?? '';
                    self::$versionInfo = [
                        'hash' => preg_match('/^[a-f0-9]+$/i', $rawHash) ? $rawHash : '',
                        'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) ? $rawDate : '',
                    ];
                    return self::$versionInfo;
                }
            }
        }

        // Versuch 2: Aus Git lesen (Entwicklungsumgebung)
        $hash = '';
        $date = '';

        // @codeCoverageIgnoreStart
        if (function_exists('exec')) {
            $output = [];
            @exec('git rev-parse --short HEAD 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), $output);
            if (!empty($output[0]) && preg_match('/^[a-f0-9]+$/i', $output[0])) {
                $hash = $output[0];
            }

            $output = [];
            @exec('git log -1 --format=%cd --date=short 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), $output);
            if (!empty($output[0]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $output[0])) {
                $date = $output[0];
            }
        }
        // @codeCoverageIgnoreEnd

        self::$versionInfo = ['hash' => $hash, 'date' => $date];
        return self::$versionInfo;
    }
}
