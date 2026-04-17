<?php

declare(strict_types=1);

namespace Tests\Support;

use DI\Bridge\Slim\Bridge as SlimBridge;
use DI\ContainerBuilder;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Basis fuer Feature-Tests: voller Slim-App-Bootstrap mit Test-DB.
 *
 * Ueberschreibt die PDO-Definition des Containers, damit die App in derselben
 * Transaktion operiert wie der Testcode. So koennen Asserts per SQL auf
 * Aenderungen der App zugreifen und die Rollback-Strategie aus der
 * IntegrationTestCase bleibt wirksam.
 */
abstract class FeatureTestCase extends IntegrationTestCase
{
    protected ?App $app = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
    }

    protected function tearDown(): void
    {
        $this->app = null;
        parent::tearDown();
    }

    /**
     * Slim-App mit Test-Container erstellen.
     * Verwendet config.php aus src/config, falls vorhanden, oder config.example.php
     * als Fallback fuer CI.
     */
    protected function createApp(): App
    {
        $configPath = APP_ROOT . '/config/config.php';
        if (!is_file($configPath)) {
            $configPath = APP_ROOT . '/config/config.example.php';
        }
        $depsPath = APP_ROOT . '/config/dependencies.php';
        $routesPath = APP_ROOT . '/config/routes.php';

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->addDefinitions($depsPath);

        // PDO durch Test-PDO ersetzen
        $testPdo = self::$pdo;
        $containerBuilder->addDefinitions([
            PDO::class => static fn () => $testPdo,
        ]);

        $container = $containerBuilder->build();

        $app = SlimBridge::create($container);

        $basePath = '';
        try {
            $settings = $container->get('settings');
            $basePath = $settings['app']['base_path'] ?? '';
        } catch (\Throwable) {
            // Settings koennen in Testumgebung fehlen - fallback auf ''
        }

        if ($basePath !== '') {
            $app->setBasePath($basePath);
        }

        \App\Helpers\ViewHelper::setBasePath($basePath);

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(true, true, true);

        (require $routesPath)($app);

        return $app;
    }

    /**
     * GET-Request gegen die App absetzen.
     *
     * @param array<string,string> $headers
     */
    protected function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $path, [], $headers);
    }

    /**
     * POST-Request gegen die App absetzen.
     *
     * @param array<string,mixed>  $data
     * @param array<string,string> $headers
     */
    protected function post(string $path, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->request('POST', $path, $data, $headers);
    }

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $headers
     */
    protected function request(string $method, string $path, array $data = [], array $headers = []): ResponseInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest($method, $path);

        foreach ($headers as $k => $v) {
            $request = $request->withHeader($k, $v);
        }

        if ($data !== [] && $method !== 'GET') {
            $request = $request
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withParsedBody($data);
        }

        return $this->app->handle($request);
    }

    protected function responseBody(ResponseInterface $response): string
    {
        $body = $response->getBody();
        $body->rewind();
        return (string) $body;
    }

    protected function assertRedirectTo(ResponseInterface $response, string $expectedPath): void
    {
        $this->assertContains($response->getStatusCode(), [301, 302, 303, 307, 308],
            'Expected redirect status, got ' . $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        $this->assertStringContainsString($expectedPath, $location,
            "Expected redirect to contain '$expectedPath', got '$location'");
    }
}
