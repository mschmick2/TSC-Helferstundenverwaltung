<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Helper-Klasse fuer MailHog-Integration in Tests
 *
 * MailHog ist ein lokaler Test-SMTP-Server mit REST-API.
 * Installation: https://github.com/mailhog/MailHog
 *
 * Standard-Ports:
 *   SMTP: 1025
 *   HTTP API: 8025
 */
class MailHogHelper
{
    private string $apiUrl;

    public function __construct(string $apiUrl = 'http://localhost:8025')
    {
        $this->apiUrl = rtrim($apiUrl, '/');
    }

    /**
     * Pruefen ob MailHog erreichbar ist
     */
    public function isAvailable(): bool
    {
        $ch = curl_init($this->apiUrl . '/api/v2/messages?limit=1');
        if ($ch === false) {
            return false;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $response !== false && $httpCode === 200;
    }

    /**
     * Alle Nachrichten abrufen
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMessages(int $limit = 50): array
    {
        $response = $this->apiRequest('/api/v2/messages?limit=' . $limit);
        return $response['items'] ?? [];
    }

    /**
     * Nachrichten nach Empfaenger filtern
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMessagesByRecipient(string $email): array
    {
        $messages = $this->getMessages();
        return array_values(array_filter($messages, function (array $msg) use ($email): bool {
            foreach ($msg['Content']['Headers']['To'] ?? [] as $to) {
                if (str_contains($to, $email)) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * Neueste Nachricht abrufen
     *
     * @return array<string, mixed>|null
     */
    public function getLatestMessage(): ?array
    {
        $messages = $this->getMessages(1);
        return $messages[0] ?? null;
    }

    /**
     * Nachrichten nach Betreff suchen
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchBySubject(string $subject): array
    {
        $response = $this->apiRequest('/api/v2/search?kind=containing&query=' . urlencode($subject));
        return $response['items'] ?? [];
    }

    /**
     * Nachrichteninhalt (Body) als Text zurueckgeben
     */
    public function getMessageBody(array $message): string
    {
        return $message['Content']['Body'] ?? '';
    }

    /**
     * Betreff einer Nachricht zurueckgeben
     */
    public function getMessageSubject(array $message): string
    {
        $headers = $message['Content']['Headers'] ?? [];
        $subjects = $headers['Subject'] ?? [];
        return $subjects[0] ?? '';
    }

    /**
     * Empfaenger einer Nachricht zurueckgeben
     *
     * @return array<int, string>
     */
    public function getMessageRecipients(array $message): array
    {
        return $message['Content']['Headers']['To'] ?? [];
    }

    /**
     * Alle Nachrichten loeschen
     */
    public function clearInbox(): void
    {
        $ch = curl_init($this->apiUrl . '/api/v1/messages');
        if ($ch === false) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
    }

    /**
     * Anzahl der Nachrichten
     */
    public function getMessageCount(): int
    {
        $response = $this->apiRequest('/api/v2/messages?limit=0');
        return $response['total'] ?? 0;
    }

    /**
     * Warten bis eine bestimmte Anzahl Nachrichten angekommen ist
     */
    public function waitForMessages(int $expectedCount, int $timeoutSeconds = 10): bool
    {
        $start = time();
        while (time() - $start < $timeoutSeconds) {
            if ($this->getMessageCount() >= $expectedCount) {
                return true;
            }
            usleep(250_000); // 250ms
        }
        return false;
    }

    /**
     * API-Request an MailHog senden
     *
     * @return array<string, mixed>
     */
    private function apiRequest(string $path): array
    {
        $ch = curl_init($this->apiUrl . $path);
        if ($ch === false) {
            return [];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false || $httpCode !== 200) {
            return [];
        }

        return json_decode($response, true) ?: [];
    }
}
