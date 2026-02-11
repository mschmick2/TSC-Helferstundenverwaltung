<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Service für den Audit-Trail
 *
 * Protokolliert JEDE Datenänderung gem. REQ-AUDIT-001 bis REQ-AUDIT-007.
 * Der Audit-Trail ist unveränderlich.
 */
class AuditService
{
    private ?int $currentUserId = null;
    private ?int $sessionId = null;
    private ?string $ipAddress = null;
    private ?string $userAgent = null;

    public function __construct(
        private \PDO $pdo
    ) {
    }

    /**
     * Kontext für den aktuellen Request setzen
     */
    public function setContext(
        ?int $userId,
        ?int $sessionId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        $this->currentUserId = $userId;
        $this->sessionId = $sessionId;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
    }

    /**
     * Allgemeinen Audit-Eintrag erstellen
     */
    public function log(
        string $action,
        ?string $tableName = null,
        ?int $recordId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?string $entryNumber = null,
        ?array $metadata = null
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO audit_log
             (user_id, session_id, ip_address, user_agent, action, table_name,
              record_id, entry_number, old_values, new_values, description, metadata, created_at)
             VALUES
             (:user_id, :session_id, :ip_address, :user_agent, :action, :table_name,
              :record_id, :entry_number, :old_values, :new_values, :description, :metadata, NOW())"
        );

        $stmt->execute([
            'user_id' => $this->currentUserId,
            'session_id' => $this->sessionId,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent ? substr($this->userAgent, 0, 500) : null,
            'action' => $action,
            'table_name' => $tableName,
            'record_id' => $recordId,
            'entry_number' => $entryNumber,
            'old_values' => $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
            'new_values' => $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
            'description' => $description,
            'metadata' => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /**
     * Erfolgreichen Login protokollieren
     */
    public function logLogin(int $userId, string $ipAddress): void
    {
        $this->currentUserId = $userId;
        $this->ipAddress = $ipAddress;
        $this->log('login', 'users', $userId, description: 'Erfolgreicher Login');
    }

    /**
     * Fehlgeschlagenen Login protokollieren
     */
    public function logLoginFailed(string $email, string $ipAddress, string $reason = ''): void
    {
        $this->ipAddress = $ipAddress;
        $this->log(
            'login_failed',
            'users',
            description: "Fehlgeschlagener Login für: {$email}" . ($reason ? " ({$reason})" : '')
        );
    }

    /**
     * Logout protokollieren
     */
    public function logLogout(int $userId): void
    {
        $this->log('logout', 'users', $userId, description: 'Logout');
    }

    /**
     * Konfigurationsänderung protokollieren
     */
    public function logConfigChange(string $key, ?string $oldValue, ?string $newValue): void
    {
        $this->log(
            'config_change',
            'settings',
            oldValues: ['value' => $oldValue],
            newValues: ['value' => $newValue],
            description: "Einstellung geändert: {$key}"
        );
    }
}
