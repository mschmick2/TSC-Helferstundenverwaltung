<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Repository für DB-basierte Session-Verwaltung
 */
class SessionRepository
{
    public function __construct(
        private \PDO $pdo
    ) {
    }

    /**
     * Neue Session erstellen
     */
    public function create(
        int $userId,
        string $token,
        ?string $ipAddress,
        ?string $userAgent,
        int $lifetimeSeconds
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO sessions (user_id, token, ip_address, user_agent, expires_at)
             VALUES (:user_id, :token, :ip, :ua, DATE_ADD(NOW(), INTERVAL :lifetime SECOND))"
        );
        $stmt->execute([
            'user_id' => $userId,
            'token' => $token,
            'ip' => $ipAddress,
            'ua' => $userAgent ? substr($userAgent, 0, 500) : null,
            'lifetime' => $lifetimeSeconds,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Session anhand Token finden
     */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM sessions WHERE token = :token AND expires_at > NOW()"
        );
        $stmt->execute(['token' => $token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Session-Aktivität aktualisieren und Ablauf verlängern
     */
    public function refresh(string $token, int $lifetimeSeconds): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sessions
             SET last_activity_at = NOW(),
                 expires_at = DATE_ADD(NOW(), INTERVAL :lifetime SECOND)
             WHERE token = :token"
        );
        $stmt->execute(['token' => $token, 'lifetime' => $lifetimeSeconds]);
    }

    /**
     * Session anhand Token löschen (Logout)
     */
    public function deleteByToken(string $token): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE token = :token");
        $stmt->execute(['token' => $token]);
    }

    /**
     * ALLE Sessions eines Benutzers löschen (bei Passwortänderung)
     */
    public function deleteAllByUser(int $userId): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
    }

    /**
     * Abgelaufene Sessions bereinigen
     */
    public function deleteExpired(): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE expires_at <= NOW()");
        $stmt->execute();
        return $stmt->rowCount();
    }
}
