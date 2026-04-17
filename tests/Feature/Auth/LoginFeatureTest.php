<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\Support\FeatureTestCase;

/**
 * Feature-Test-Skelett: Login-Flow.
 *
 * Dieser Test dokumentiert das Pattern fuer Feature-Tests.
 * Er setzt eine lauffaehige vaes_test-DB mit Schema voraus.
 *
 * Ausfuehren:
 *   src/vendor/bin/phpunit --testsuite Feature
 */
final class LoginFeatureTest extends FeatureTestCase
{
    public function test_get_login_page_returns_200(): void
    {
        $response = $this->get('/login');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('csrf', strtolower($this->responseBody($response)));
    }

    public function test_login_with_invalid_credentials_returns_to_login(): void
    {
        // CSRF-Token setzen (vereinfachtes Pattern — Feature-Tests duerfen Session manipulieren)
        $_SESSION['csrf_token'] = 'test-token';

        $response = $this->post('/login', [
            'csrf_token' => 'test-token',
            'email'      => 'unbekannt@test.local',
            'password'   => 'falsch',
        ]);

        // Erwartung: 200 mit Fehlermeldung oder Redirect zurueck auf /login
        self::assertContains($response->getStatusCode(), [200, 302, 303]);
    }
}
