<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Client fuer die MailPit-REST-API (http://127.0.0.1:8025).
 *
 * MailPit wird als lokaler Test-SMTP-Server gestartet und faengt E-Mails
 * auf Port 1025 ab. Die REST-API dient Tests zum Inspizieren, Suchen und
 * Loeschen.
 *
 * Unterschied zu MailHog: MailPit verwendet /api/v1/* statt /api/v2/*.
 */
final class MailPitClient
{
    public function __construct(
        private readonly string $apiUrl = 'http://127.0.0.1:8025'
    ) {}

    public static function fromEnv(): self
    {
        $url = getenv('MAILPIT_URL') ?: 'http://127.0.0.1:8025';
        return new self(rtrim($url, '/'));
    }

    /**
     * Pruefen, ob MailPit erreichbar ist.
     */
    public function isAvailable(): bool
    {
        $res = $this->get('/api/v1/info');
        return $res !== null;
    }

    /**
     * Alle Nachrichten abrufen (neueste zuerst).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMessages(int $limit = 50): array
    {
        $res = $this->get("/api/v1/messages?limit=$limit");
        return $res['messages'] ?? [];
    }

    /**
     * Volle Nachricht inklusive Bodies laden.
     *
     * @return array<string, mixed>|null
     */
    public function getMessage(string $id): ?array
    {
        return $this->get("/api/v1/message/" . rawurlencode($id));
    }

    /**
     * Suche nach Subject/Body/From/To.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query): array
    {
        $res = $this->get('/api/v1/search?query=' . urlencode($query));
        return $res['messages'] ?? [];
    }

    /**
     * Erste Nachricht abrufen, die fuer einen Empfaenger bestimmt ist.
     *
     * @return array<string, mixed>|null
     */
    public function findByRecipient(string $email): ?array
    {
        foreach ($this->getMessages() as $msg) {
            $to = $msg['To'] ?? [];
            foreach ($to as $recipient) {
                $addr = $recipient['Address'] ?? '';
                if (strcasecmp($addr, $email) === 0) {
                    return $msg;
                }
            }
        }
        return null;
    }

    /**
     * Auf eine Nachricht warten, die to/subject matcht.
     *
     * @param array{to?:string,subject?:string,timeoutMs?:int,pollMs?:int} $criteria
     * @return array<string, mixed>|null
     */
    public function waitForMessage(array $criteria): ?array
    {
        $timeout = (int) ($criteria['timeoutMs'] ?? 10000);
        $poll    = (int) ($criteria['pollMs']    ?? 500);
        $deadline = microtime(true) + $timeout / 1000;

        while (microtime(true) < $deadline) {
            $messages = $this->getMessages();
            foreach ($messages as $msg) {
                if (!empty($criteria['to'])) {
                    $match = false;
                    foreach ($msg['To'] ?? [] as $r) {
                        if (strcasecmp($r['Address'] ?? '', $criteria['to']) === 0) {
                            $match = true;
                            break;
                        }
                    }
                    if (!$match) {
                        continue;
                    }
                }
                if (!empty($criteria['subject'])) {
                    $subject = $msg['Subject'] ?? '';
                    if (stripos($subject, (string) $criteria['subject']) === false) {
                        continue;
                    }
                }
                return $msg;
            }
            usleep($poll * 1000);
        }
        return null;
    }

    /**
     * Anzahl der Nachrichten.
     */
    public function count(): int
    {
        $res = $this->get('/api/v1/info');
        return (int) ($res['Messages'] ?? 0);
    }

    /**
     * Alle Nachrichten loeschen.
     */
    public function deleteAll(): void
    {
        $this->request('DELETE', '/api/v1/messages');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function get(string $path): ?array
    {
        return $this->request('GET', $path);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $path): ?array
    {
        $ch = curl_init($this->apiUrl . $path);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            return null;
        }
        $decoded = json_decode((string) $body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
