<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invariants fuer das I8 G4-ROT-Fix-Pattern (FU-I8-G4-0).
 *
 * Nach der Umstellung muss jedes `catch (AuthorizationException $e)` in
 * den drei relevanten Controllern zwingend `handleAuthorizationDenial`
 * aufrufen -- sonst umgeht ein zukuenftiger catch-Block wieder den
 * Audit-Pfad und der ROT-Fix wird unterlaufen.
 *
 * Zweite Invariante: das alte kombinierte Pattern
 * `catch (BusinessRuleException | AuthorizationException $e)` (und
 * umgekehrt) darf in den drei Controllern nicht mehr auftauchen, weil
 * nur der spezifische AuthorizationException-Fall Audit braucht und
 * der Helper returnt (BusinessRuleException soll nur flashen und zum
 * gemeinsamen Return weiter).
 */
final class AuthorizationCatchBlockInvariantsTest extends TestCase
{
    private const CONTROLLERS = [
        'WorkEntryController.php',
        'OrganizerEventController.php',
        'MemberEventController.php',
    ];

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function controllerProvider(): array
    {
        $out = [];
        foreach (self::CONTROLLERS as $file) {
            $path = __DIR__ . '/../../../src/app/Controllers/' . $file;
            $out[$file] = [$file, $path];
        }
        return $out;
    }

    /**
     * @dataProvider controllerProvider
     */
    public function test_authorization_catch_blocks_use_helper(
        string $fileName,
        string $path
    ): void {
        $source = (string) file_get_contents($path);

        // Alle Solo-catch(AuthorizationException ...)-Bloecke inkl. ihres
        // Body matchen. `\{((?:[^{}]|(?R))*)\}` waere sauberer, aber die
        // Bloecke sind hier nicht verschachtelt -- eine einfache nicht-
        // gierige Suche bis zur naechsten schliessenden Klammer reicht.
        $matched = preg_match_all(
            '/catch\s*\(\s*AuthorizationException\s+\$\w+\s*\)\s*\{([^{}]*)\}/s',
            $source,
            $matches
        );

        self::assertNotFalse($matched, "Regex-Fehler auf {$fileName}.");
        self::assertGreaterThan(
            0,
            $matched,
            "{$fileName} sollte mindestens einen AuthorizationException-"
            . 'Catch enthalten (sonst ist der Fix in der falschen Datei).'
        );

        foreach ($matches[1] as $index => $body) {
            self::assertStringContainsString(
                'handleAuthorizationDenial',
                $body,
                sprintf(
                    '%s Block #%d catcht AuthorizationException, ruft aber '
                    . 'nicht handleAuthorizationDenial -- Audit-Leak, '
                    . 'FU-I8-G4-0 wird unterlaufen.',
                    $fileName,
                    $index + 1
                )
            );
        }
    }

    /**
     * @dataProvider controllerProvider
     */
    public function test_no_combined_authorization_business_catch(
        string $fileName,
        string $path
    ): void {
        $source = (string) file_get_contents($path);

        // Beide Reihenfolgen des alten Patterns pruefen.
        $combined = preg_match(
            '/catch\s*\(\s*(AuthorizationException\s*\|\s*BusinessRuleException|BusinessRuleException\s*\|\s*AuthorizationException)\s+\$\w+\s*\)/',
            $source
        );

        self::assertSame(
            0,
            $combined,
            "{$fileName} enthaelt noch ein kombiniertes "
            . '(AuthorizationException | BusinessRuleException)-Catch. '
            . 'Nach dem FU-I8-G4-0-Fix muessen AuthorizationException und '
            . 'BusinessRuleException in getrennten Bloecken stehen, weil '
            . 'nur AuthorizationException durch den Audit-Helper laeuft.'
        );
    }

    public function test_expected_count_of_authorization_catches_across_controllers(): void
    {
        // Grobes Sicherheitsnetz: die drei Controller zusammen haben
        // laut G4-Bericht 16 Catch-Stellen. Wenn die Zahl stark abweicht,
        // wurde entweder eine Stelle vergessen oder eine Stelle ist neu
        // dazugekommen -- in beiden Faellen soll ein Mensch draufschauen.
        $total = 0;
        foreach (self::CONTROLLERS as $file) {
            $path = __DIR__ . '/../../../src/app/Controllers/' . $file;
            $source = (string) file_get_contents($path);
            $total += preg_match_all(
                '/catch\s*\(\s*AuthorizationException\s+\$\w+\s*\)/',
                $source
            );
        }

        self::assertSame(
            16,
            $total,
            'Erwartet: 16 AuthorizationException-Catches in den drei '
            . 'Controllern (10 WorkEntry, 4 Organizer, 2 Member). '
            . 'Abweichung bitte pruefen: entweder Stelle vergessen oder '
            . 'neuer catch ohne Invariants-Aktualisierung.'
        );
    }
}
