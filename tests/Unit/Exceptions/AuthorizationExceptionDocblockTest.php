<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;

/**
 * Statischer Invariant aus Modul 6 I8 Follow-up FU-3.
 *
 * Hintergrund: der Slim-ErrorHandler in `src/public/index.php` gibt
 * `AuthorizationException::getMetadata()` unveraendert an
 * `AuditService::logAccessDenied` weiter. Jede PII, die ein kuenftiger
 * Werfer ins `$metadata`-Array legt, landet damit in der audit_log-
 * Retention (10 Jahre). Die Konvention "keine PII in metadata" ist im
 * __construct-Docblock kodifiziert; dieser Test stellt sicher, dass der
 * Hinweis nicht versehentlich entfernt oder zu einer nackten Warnung
 * ausgeduennt wird.
 */
final class AuthorizationExceptionDocblockTest extends TestCase
{
    public function test_metadata_docblock_warns_about_pii_with_concrete_examples(): void
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../../../src/app/Exceptions/AuthorizationException.php'
        );

        self::assertStringContainsString(
            'keine PII',
            $source,
            'AuthorizationException::__construct muss im Docblock vor PII '
            . 'in $metadata warnen (FU-3 aus I8 Sanity-Gate).'
        );

        // Generische Warnung allein reicht nicht -- ohne konkrete Beispiele
        // interpretieren Autoren "PII" unterschiedlich weit. Der Docblock
        // muss mindestens drei klassische Leaks explizit ausschliessen.
        foreach (['E-Mail', 'Namen', 'Request-Body'] as $needle) {
            self::assertStringContainsString(
                $needle,
                $source,
                sprintf(
                    'AuthorizationException-Docblock muss %s als verbotenes '
                    . 'Metadata-Beispiel nennen.',
                    $needle
                )
            );
        }
    }
}
