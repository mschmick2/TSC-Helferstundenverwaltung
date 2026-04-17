<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Support\TestCase;

/**
 * Statische Pruefung: keine SQL-Konkatenation im Code.
 *
 * Durchsucht src/app nach verdaechtigen Mustern wie "query(...$...)" oder
 * "exec(...$...)". Fehlalarm moeglich, aber als Leitplanke nuetzlich.
 *
 * Whitelist konkreter Falsch-Treffer via $allowedFalsePositives.
 */
final class SqlInjectionAuditTest extends TestCase
{
    /** @var list<string> Patterns, die definitiv verdaechtig sind */
    private array $suspiciousPatterns = [
        '/->query\s*\(\s*["\'][^"\']*\$[a-zA-Z_]/',
        '/->exec\s*\(\s*["\'][^"\']*\$[a-zA-Z_]/',
        '/\bmysqli_query\s*\(/',
        '/\bmysql_query\s*\(/',
    ];

    /** @var list<string> Dateien, die bewusst Ausnahmen darstellen duerfen */
    private array $allowedFalsePositives = [
        // z.B. 'src/app/Repositories/LegacyRepo.php'
    ];

    public function test_no_sql_concatenation_in_app_code(): void
    {
        $appDir = APP_ROOT . '/app';
        self::assertDirectoryExists($appDir);

        $findings = [];
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($rii as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relPath = str_replace('\\', '/', str_replace(APP_ROOT . '/', '', $file->getPathname()));
            if (in_array($relPath, $this->allowedFalsePositives, true)) {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());
            foreach ($this->suspiciousPatterns as $pattern) {
                if (preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
                    $line = substr_count(substr($content, 0, (int) $m[0][1]), "\n") + 1;
                    $findings[] = "$relPath:$line — Muster '{$m[0][0]}'";
                }
            }
        }

        self::assertEmpty(
            $findings,
            "SQL-Konkatenation oder unsichere MySQL-Funktionen gefunden:\n" . implode("\n", $findings)
        );
    }
}
