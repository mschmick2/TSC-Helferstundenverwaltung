<?php

declare(strict_types=1);

namespace App\Services;

/**
 * IP-basiertes Rate-Limiting (MySQL-basiert, Strato-kompatibel)
 *
 * Schützt öffentliche Endpunkte vor Brute-Force und E-Mail-Bombing.
 */
class RateLimitService
{
    public function __construct(
        private \PDO $pdo
    ) {
    }

    /**
     * Prüft ob ein Request erlaubt ist
     *
     * @param string $ipAddress IP-Adresse des Clients
     * @param string $endpoint Endpunkt-Kennung (z.B. 'forgot-password')
     * @param int $maxAttempts Maximale Versuche im Zeitfenster
     * @param int $windowSeconds Zeitfenster in Sekunden
     * @return bool true wenn erlaubt, false wenn Rate-Limit erreicht
     */
    public function isAllowed(string $ipAddress, string $endpoint, int $maxAttempts, int $windowSeconds): bool
    {
        // Alte Einträge bereinigen (gelegentlich, 10% Wahrscheinlichkeit)
        if (random_int(1, 10) === 1) {
            $this->cleanup($windowSeconds * 2);
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM rate_limits
             WHERE ip_address = :ip AND endpoint = :endpoint
             AND attempted_at > DATE_SUB(NOW(), INTERVAL :window SECOND)"
        );
        $stmt->execute([
            'ip' => $ipAddress,
            'endpoint' => $endpoint,
            'window' => $windowSeconds,
        ]);

        return (int) $stmt->fetchColumn() < $maxAttempts;
    }

    /**
     * Einen Request-Versuch registrieren
     */
    public function recordAttempt(string $ipAddress, string $endpoint): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO rate_limits (ip_address, endpoint) VALUES (:ip, :endpoint)"
        );
        $stmt->execute([
            'ip' => $ipAddress,
            'endpoint' => $endpoint,
        ]);
    }

    /**
     * Alte Einträge bereinigen
     */
    private function cleanup(int $olderThanSeconds): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM rate_limits WHERE attempted_at < DATE_SUB(NOW(), INTERVAL :seconds SECOND)"
        );
        $stmt->execute(['seconds' => $olderThanSeconds]);
    }
}
