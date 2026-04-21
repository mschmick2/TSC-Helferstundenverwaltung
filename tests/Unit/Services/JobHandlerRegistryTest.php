<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Jobs\JobHandler;
use App\Services\Jobs\JobHandlerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

class JobHandlerRegistryTest extends TestCase
{
    /** @test */
    public function resolve_liefert_registrierten_handler(): void
    {
        $handler = new class implements JobHandler {
            public array $calledWith = [];
            public function handle(array $payload): void
            {
                $this->calledWith = $payload;
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with($handler::class)->willReturn($handler);

        $registry = new JobHandlerRegistry($container);
        $registry->register('demo_job', $handler::class);

        $resolved = $registry->resolve('demo_job');
        $resolved->handle(['x' => 1]);

        $this->assertSame($handler, $resolved);
        $this->assertSame(['x' => 1], $handler->calledWith);
    }

    /** @test */
    public function resolve_unbekannter_typ_wirft_exception(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $registry = new JobHandlerRegistry($container);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kein Handler registriert fuer Job-Typ: mystery');
        $registry->resolve('mystery');
    }

    /** @test */
    public function resolve_klasse_die_kein_handler_ist_wirft_exception(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn(new \stdClass());

        $registry = new JobHandlerRegistry($container);
        $registry->register('bad', 'stdClass');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('implementiert nicht JobHandler');
        $registry->resolve('bad');
    }

    /** @test */
    public function is_registered_und_known_types(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $registry = new JobHandlerRegistry($container);

        $this->assertFalse($registry->isRegistered('demo'));
        $this->assertSame([], $registry->knownTypes());

        $registry->register('demo', 'App\\Services\\Jobs\\DemoHandler');
        $registry->register('other', 'App\\Services\\Jobs\\OtherHandler');

        $this->assertTrue($registry->isRegistered('demo'));
        $this->assertSame(['demo', 'other'], $registry->knownTypes());
    }
}
