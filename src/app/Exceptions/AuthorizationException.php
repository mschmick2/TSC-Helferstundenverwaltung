<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Geworfen, wenn ein authentifizierter User eine Aktion anfordert, fuer
 * die er keine Berechtigung hat.
 *
 * Seit Modul 6 I8 Phase 1 (Follow-up v aus I7e-A): ein zentraler Slim-
 * ErrorHandler fuer diese Exception schreibt automatisch einen
 * audit_log-Eintrag (action='access_denied') via
 * AuditService::logAccessDenied, bevor die Response gebaut wird. Der
 * `reason`-Machine-Code differenziert die Ursachen fuer den Auditor
 * (missing_role, not_organizer, resource_not_found, csrf_invalid,
 * rate_limited).
 *
 * Bestehende Aufrufer (BaseController::assertEventEditPermission,
 * WorkflowService, WorkEntryController, EventAssignmentService) werfen
 * ohne zusaetzliche Parameter -- `reason` default 'missing_role' passt
 * fuer alle vier und kann bei Bedarf feiner differenziert werden.
 */
class AuthorizationException extends \RuntimeException
{
    /** @var array<string, mixed> */
    private array $metadata;

    /**
     * @param string               $message  Fehler-Text fuer Logs und die
     *                                       Dev-Umgebung. Wird an die
     *                                       Flash/JSON-Response weitergereicht.
     * @param string               $reason   Machine-Code fuer die Audit-
     *                                       Auswertung. Default:
     *                                       `missing_role`. Aktuell ebenfalls
     *                                       genutzt: `csrf_invalid`,
     *                                       `rate_limited`,
     *                                       `ownership_violation`,
     *                                       `resource_not_found`.
     * @param array<string, mixed> $metadata Zusatz-Kontext fuer den Audit-
     *                                       Eintrag.
     *
     * **Wichtig -- keine PII in `$metadata` aufnehmen.** Der Slim-ErrorHandler
     * (siehe `src/public/index.php` I8 Phase 1) gibt das Array unveraendert
     * an `AuditService::logAccessDenied` weiter, wo es als JSON in
     * `audit_log.metadata` landet -- ohne Filterung oder Whitelist. Die
     * Retention betraegt 10 Jahre (Audit-Log-Regel).
     *
     * Erlaubt sind maschinen-lesbare Codes und interne IDs (z.B.
     * `event_id`, `task_id`, `bucket`, `required_roles`, `limit`,
     * `window_seconds`). Verboten sind E-Mail-Adressen, Namen,
     * IP-Adressen (die werden separat via Request-Kontext geloggt),
     * Request-Body-Inhalte, User-Eingaben und freitext-Begruendungen.
     */
    public function __construct(
        string $message = '',
        private readonly string $reason = 'missing_role',
        array $metadata = [],
    ) {
        parent::__construct($message);
        $this->metadata = $metadata;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
