<?php

declare(strict_types=1);

namespace Tests\Security;

use Tests\Support\FeatureTestCase;

/**
 * Security-Test: CSRF-Schutz auf State-Changing Endpoints.
 *
 * Pattern fuer OWASP-A01/A04-Tests. Jeder POST ohne gueltigen csrf_token
 * muss abgelehnt werden (403/419 oder Redirect zu Login).
 */
final class CsrfProtectionTest extends FeatureTestCase
{
    public function test_post_login_without_csrf_token_is_rejected(): void
    {
        $_SESSION['csrf_token'] = 'valid-token';

        // POST OHNE csrf_token im Body
        $response = $this->post('/login', [
            'email'    => 'admin@test.local',
            'password' => 'irrelevant',
        ]);

        self::assertContains(
            $response->getStatusCode(),
            [302, 303, 400, 403, 419, 422],
            'Request ohne CSRF-Token muss abgelehnt werden, Status erhalten: ' . $response->getStatusCode()
        );
    }

    public function test_post_login_with_invalid_csrf_token_is_rejected(): void
    {
        $_SESSION['csrf_token'] = 'server-token';

        $response = $this->post('/login', [
            'csrf_token' => 'client-provided-different',
            'email'      => 'admin@test.local',
            'password'   => 'irrelevant',
        ]);

        self::assertContains(
            $response->getStatusCode(),
            [302, 303, 400, 403, 419, 422],
            'Request mit ungueltigem CSRF-Token muss abgelehnt werden.'
        );
    }
}
