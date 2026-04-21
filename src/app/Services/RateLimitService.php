<?php

declare(strict_types=1);

namespace App\Services;

/**
 * IP- und Email-basiertes Rate-Limiting (MySQL-basiert, Strato-kompatibel)
 *
 * Schützt öffentliche Endpunkte vor Brute-Force und E-Mail-Bombing. Die IP-
 * basierten Methoden (isAllowed, recordAttempt) sichern gegen einzelne Angreifer
 * ab; die Email-basierten Methoden (isAllowedForEmail, recordAttemptForEmail)
 * sichern zusätzlich gegen verteilte Angriffe auf ein einzelnes Empfänger-Postfach
 * (z.B. Reset-Mail-Flood für ein Opfer aus einem Botnet).
 */
class RateLimitService
{
    public function __construct(
        private \PDO $pdo
    ) {
    }

    /**
     * Prüft ob ein Request erlaubt ist (IP-Bucket)
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
     * Prüft ob ein Request erlaubt ist (Email-Bucket)
     *
     * Zweck: verteilte Angriffe aus vielen IPs, die ein einzelnes Postfach mit
     * Reset-Mails fluten wollen, limitieren. Der Check ist unabhängig vom IP-
     * Bucket und wird parallel dazu geprüft.
     *
     * @param string $email Empfänger-Email (lowercased empfohlen)
     * @param string $endpoint Endpunkt-Kennung (z.B. 'forgot-password')
     * @param int $maxAttempts Maximale Versuche im Zeitfenster
     * @param int $windowSeconds Zeitfenster in Sekunden
     * @return bool true wenn erlaubt, false wenn Rate-Limit erreicht
     */
    public function isAllowedForEmail(string $email, string $endpoint, int $maxAttempts, int $windowSeconds): bool
    {
        if (random_int(1, 10) === 1) {
            $this->cleanup($windowSeconds * 2);
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM rate_limits
             WHERE email = :email AND endpoint = :endpoint
             AND attempted_at > DATE_SUB(NOW(), INTERVAL :window SECOND)"
        );
        $stmt->execute([
            'email' => $email,
            'endpoint' => $endpoint,
            'window' => $windowSeconds,
        ]);

        return (int) $stmt->fetchColumn() < $maxAttempts;
    }

    /**
     * Einen IP-Request-Versuch registrieren
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
     * Einen Request-Versuch mit IP + Email registrieren (ein Row zählt für beide Buckets)
     *
     * Ein einzelner Insert mit gefüllter email-Spalte wird sowohl vom
     * IP-Bucket-Count als auch vom Email-Bucket-Count als Versuch gezählt. Das
     * vermeidet Doppel-Writes und verzerrt die IP-Zählung nicht.
     */
    public function recordAttemptForEmail(string $ipAddress, string $email, string $endpoint): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO rate_limits (ip_address, email, endpoint) VALUES (:ip, :email, :endpoint)"
        );
        $stmt->execute([
            'ip' => $ipAddress,
            'email' => $email,
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
