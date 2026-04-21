<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Mappt job_type (String) auf Handler-Klasse.
 * Container-lookup, damit Handler ueber DI ihre Abhaengigkeiten bekommen.
 */
class JobHandlerRegistry
{
    /** @var array<string, class-string<JobHandler>> */
    private array $handlers = [];

    public function __construct(
        private ContainerInterface $container
    ) {
    }

    /**
     * @param class-string<JobHandler> $handlerClass
     */
    public function register(string $jobType, string $handlerClass): void
    {
        $this->handlers[$jobType] = $handlerClass;
    }

    public function resolve(string $jobType): JobHandler
    {
        if (!isset($this->handlers[$jobType])) {
            throw new RuntimeException("Kein Handler registriert fuer Job-Typ: {$jobType}");
        }

        $handlerClass = $this->handlers[$jobType];
        $handler = $this->container->get($handlerClass);

        if (!$handler instanceof JobHandler) {
            throw new RuntimeException(
                "Handler {$handlerClass} implementiert nicht JobHandler"
            );
        }

        return $handler;
    }

    public function isRegistered(string $jobType): bool
    {
        return isset($this->handlers[$jobType]);
    }

    /**
     * @return array<int, string>
     */
    public function knownTypes(): array
    {
        return array_keys($this->handlers);
    }
}
