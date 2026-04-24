<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;

/**
 * Statische Invarianten fuer die Rate-Limit-Verkabelung aus
 * Modul 6 I8 Phase 2 (FU-G4-1).
 *
 * Ziel: die vier Drehpunkte der Bindung (DI-Factory, Route-Group-
 * Imports, per-Route-Adds, Settings-Keys) bleiben bei kuenftigem
 * Refactoring sichtbar. Regex-Pruefungen gegen die Dateien; kein
 * Runtime-Test.
 */
final class RateLimitWiringInvariantsTest extends TestCase
{
    private const ROUTES =
        __DIR__ . '/../../../src/config/routes.php';
    private const DEPENDENCIES =
        __DIR__ . '/../../../src/config/dependencies.php';
    private const MIDDLEWARE =
        __DIR__ . '/../../../src/app/Middleware/RateLimitMiddleware.php';
    private const MIGRATION =
        __DIR__ . '/../../../scripts/database/migrations/011_audit_denial_and_rate_limits.sql';
    private const SERVICE =
        __DIR__ . '/../../../src/app/Services/RateLimitService.php';

    private function read(string $path): string
    {
        return (string) file_get_contents($path);
    }

    // =========================================================================
    // DI-Container: drei String-Keyed Factories
    // =========================================================================

    public function test_di_container_has_three_rate_limit_factories(): void
    {
        $code = $this->read(self::DEPENDENCIES);
        foreach ([
            'RateLimitMiddleware.treeAction',
            'RateLimitMiddleware.editSessionHeartbeat',
            'RateLimitMiddleware.editSessionOther',
        ] as $key) {
            self::assertStringContainsString(
                "'{$key}'",
                $code,
                "dependencies.php muss String-Factory '{$key}' registrieren."
            );
        }
    }

    public function test_di_factories_use_distinct_bucket_and_setting_keys(): void
    {
        $code = $this->read(self::DEPENDENCIES);
        // Jede Factory muss ihren eigenen Bucket-String setzen und
        // die zugehoerigen Settings-Keys referenzieren.
        self::assertStringContainsString("'tree_action'", $code);
        self::assertStringContainsString("'edit_session_heartbeat'", $code);
        self::assertStringContainsString("'edit_session_other'", $code);
        self::assertStringContainsString(
            "'security.tree_action_rate_limit_max'",
            $code
        );
        self::assertStringContainsString(
            "'security.edit_session_heartbeat_rate_limit_max'",
            $code
        );
        self::assertStringContainsString(
            "'security.edit_session_other_rate_limit_max'",
            $code
        );
    }

    // =========================================================================
    // routes.php: Container-Lookup am Anfang + per-Route Adds
    // =========================================================================

    public function test_routes_resolve_three_rate_limit_instances_at_startup(): void
    {
        $code = $this->read(self::ROUTES);
        self::assertStringContainsString(
            "'RateLimitMiddleware.treeAction'",
            $code,
            'routes.php muss RateLimitMiddleware.treeAction aus dem '
            . 'Container ziehen.'
        );
        self::assertStringContainsString(
            "'RateLimitMiddleware.editSessionHeartbeat'",
            $code,
            'routes.php muss RateLimitMiddleware.editSessionHeartbeat '
            . 'aus dem Container ziehen.'
        );
        self::assertStringContainsString(
            "'RateLimitMiddleware.editSessionOther'",
            $code,
            'routes.php muss RateLimitMiddleware.editSessionOther aus '
            . 'dem Container ziehen.'
        );
    }

    public function test_heartbeat_route_has_dedicated_rate_limit(): void
    {
        $code = $this->read(self::ROUTES);
        // Die Heartbeat-Route muss ein $heartbeatRoute->add(...) mit
        // $rateLimitHeartbeat haben.
        self::assertMatchesRegularExpression(
            '/\$heartbeatRoute\s*->\s*add\s*\(\s*\$rateLimitHeartbeat\s*\)/',
            $code,
            'Heartbeat-Route muss rateLimitHeartbeat per ->add() erhalten.'
        );
    }

    public function test_start_and_close_routes_share_rate_limit_other(): void
    {
        $code = $this->read(self::ROUTES);
        self::assertMatchesRegularExpression(
            '/\$startRoute\s*->\s*add\s*\(\s*\$rateLimitOther\s*\)/',
            $code,
            'Start-Route muss rateLimitOther per ->add() erhalten.'
        );
        self::assertMatchesRegularExpression(
            '/\$closeRoute\s*->\s*add\s*\(\s*\$rateLimitOther\s*\)/',
            $code,
            'Close-Route muss rateLimitOther per ->add() erhalten.'
        );
    }

    public function test_admin_tree_routes_add_rate_limit_in_foreach(): void
    {
        $code = $this->read(self::ROUTES);
        // In der /admin-Gruppe werden Tree-Routes in $adminTreeRoutes
        // gesammelt und am Ende ueber foreach mit $rateLimitTreeAction
        // dekoriert.
        self::assertMatchesRegularExpression(
            '/\$adminTreeRoutes\s*\[\]\s*=\s*\$group->/',
            $code,
            'Admin-Gruppe muss Tree-Routes in $adminTreeRoutes sammeln.'
        );
        self::assertMatchesRegularExpression(
            '/foreach\s*\(\s*\$adminTreeRoutes\s+as\s+\$route\s*\)/',
            $code,
            'Admin-Gruppe muss $adminTreeRoutes per foreach dekorieren.'
        );
    }

    public function test_organizer_tree_routes_add_rate_limit_in_foreach(): void
    {
        $code = $this->read(self::ROUTES);
        self::assertMatchesRegularExpression(
            '/\$organizerTreeRoutes\s*\[\]\s*=\s*\$group->/',
            $code,
            'Organizer-Gruppe muss Tree-Routes in $organizerTreeRoutes '
            . 'sammeln.'
        );
        self::assertMatchesRegularExpression(
            '/foreach\s*\(\s*\$organizerTreeRoutes\s+as\s+\$route\s*\)/',
            $code,
            'Organizer-Gruppe muss $organizerTreeRoutes per foreach '
            . 'dekorieren.'
        );
    }

    // =========================================================================
    // Middleware-Implementation: 429 + Audit
    // =========================================================================

    public function test_middleware_returns_429_on_rate_limit(): void
    {
        $code = $this->read(self::MIDDLEWARE);
        self::assertMatchesRegularExpression(
            '/withStatus\s*\(\s*429\s*\)/',
            $code,
            'RateLimitMiddleware muss 429 als Status fuer Limit-'
            . 'Ueberschreitung zurueckgeben.'
        );
    }

    public function test_middleware_sets_retry_after_header(): void
    {
        $code = $this->read(self::MIDDLEWARE);
        self::assertMatchesRegularExpression(
            "/withHeader\s*\(\s*'Retry-After'/",
            $code,
            'RateLimitMiddleware muss Retry-After-Header in der '
            . '429-Response setzen.'
        );
    }

    public function test_middleware_logs_rate_limited_to_audit(): void
    {
        $code = $this->read(self::MIDDLEWARE);
        self::assertStringContainsString(
            'logAccessDenied',
            $code,
            'RateLimitMiddleware muss logAccessDenied aufrufen (C13).'
        );
        self::assertMatchesRegularExpression(
            "/reason:\s*'rate_limited'/",
            $code,
            'RateLimitMiddleware muss reason=rate_limited setzen.'
        );
    }

    public function test_middleware_records_attempt_before_handler(): void
    {
        $code = $this->read(self::MIDDLEWARE);
        // recordAttemptForUser muss VOR handle() aufgerufen werden,
        // damit ein exception-werfender Handler den Counter nicht
        // umgehen kann.
        $posRecord = strpos($code, 'recordAttemptForUser');
        $posHandle = strpos($code, '$handler->handle($request)');
        self::assertNotFalse($posRecord, 'recordAttemptForUser fehlt.');
        self::assertNotFalse($posHandle, '$handler->handle fehlt.');
        self::assertLessThan(
            $posHandle,
            $posRecord,
            'recordAttemptForUser muss VOR $handler->handle stehen '
            . '(Architect-Fallstrick 4).'
        );
    }

    // =========================================================================
    // Migration 011: Settings-Keys vorhanden
    // =========================================================================

    // =========================================================================
    // RateLimitService: neue Methoden-Signaturen + SQL-Pattern
    // =========================================================================

    public function test_service_has_isAllowedForUser_and_recordAttemptForUser(): void
    {
        $code = $this->read(self::SERVICE);
        self::assertMatchesRegularExpression(
            '/public\s+function\s+isAllowedForUser\s*\(\s*int\s+\$userId/',
            $code,
            'RateLimitService muss isAllowedForUser(int $userId, ...) '
            . 'anbieten.'
        );
        self::assertMatchesRegularExpression(
            '/public\s+function\s+recordAttemptForUser\s*\(\s*int\s+\$userId/',
            $code,
            'RateLimitService muss recordAttemptForUser(int $userId, ...) '
            . 'anbieten.'
        );
    }

    public function test_service_user_methods_use_user_prefix_email_key(): void
    {
        $code = $this->read(self::SERVICE);
        // Beide neuen Methoden muessen den Key-Prefix 'user:' auf die
        // userId setzen. Das ist die Kern-Invariante fuer das
        // Namespace-Misuse der email-Spalte.
        self::assertStringContainsString(
            "'user:' . \$userId",
            $code,
            'User-Methoden muessen den User-Key im Format user:<id> '
            . 'zusammensetzen (Architect Q6).'
        );
    }

    public function test_service_isAllowedForUser_filters_by_email_and_endpoint_and_window(): void
    {
        $code = $this->read(self::SERVICE);
        // Die MySQL-Query in isAllowedForUser muss drei Filter halten:
        // email, endpoint und das Zeit-Window via DATE_SUB(NOW(), ...).
        self::assertMatchesRegularExpression(
            '/WHERE\s+email\s*=\s*:user_key\s+AND\s+endpoint/',
            $code,
            'isAllowedForUser muss WHERE email = :user_key AND endpoint '
            . 'filtern.'
        );
        self::assertMatchesRegularExpression(
            '/DATE_SUB\s*\(\s*NOW\(\)\s*,\s*INTERVAL\s+:window\s+SECOND/',
            $code,
            'isAllowedForUser muss die Zeit-Window-Filterung via '
            . 'DATE_SUB(NOW(), INTERVAL :window SECOND) erzwingen.'
        );
    }

    public function test_migration_011_inserts_six_rate_limit_setting_keys(): void
    {
        $code = $this->read(self::MIGRATION);
        foreach ([
            'security.tree_action_rate_limit_max',
            'security.tree_action_rate_limit_window',
            'security.edit_session_heartbeat_rate_limit_max',
            'security.edit_session_heartbeat_rate_limit_window',
            'security.edit_session_other_rate_limit_max',
            'security.edit_session_other_rate_limit_window',
        ] as $key) {
            self::assertStringContainsString(
                "'{$key}'",
                $code,
                "Migration 011 muss Settings-Key {$key} inserten."
            );
        }
    }
}
