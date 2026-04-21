<?php

declare(strict_types=1);

namespace Tests\Support;

use Mockery;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Basis-Testklasse fuer reine Unit-Tests.
 *
 * - Keine DB
 * - Keine HTTP-Calls
 * - Mockery wird in tearDown aufgeraeumt
 */
abstract class TestCase extends PhpUnitTestCase
{
    protected function tearDown(): void
    {
        if (class_exists(Mockery::class)) {
            Mockery::close();
        }
        parent::tearDown();
    }

    /**
     * Liest eine env-Variable aus phpunit.xml oder $_ENV.
     */
    protected function env(string $key, string $default = ''): string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            return $default;
        }
        return $v;
    }
}
