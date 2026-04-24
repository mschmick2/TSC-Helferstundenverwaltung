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
     * @param array<string, mixed> $metadata
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
