<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SettingsRepository;
use App\Services\RateLimitService;
use App\Services\SchedulerService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Externer Cron-Pinger-Endpunkt (Strato-Ersatz fuer System-Cron).
 *
 * POST /cron/run
 *   Header:  X-Cron-Token: <token>
 *   Antwort: 200 JSON {ok, processed?, failed?, run_id?, skipped?, reason?}
 *            401 wenn Token fehlt/falsch oder kein Hash hinterlegt
 *            429 bei Rate-Limit-Ueberschreitung
 *            503 wenn Feature-Flag notifications_enabled=false
 *
 * Sicherheits-Schichten:
 *   1. Rate-Limit pro IP (6 Requests / 60 s) gegen Brute-Force / DoS.
 *   2. Token = 64-Hex-String (256 Bit Entropie); gespeichert wird nur der
 *      SHA-256-Hash in settings.cron_external_token_hash.
 *   3. Vergleich timing-safe via hash_equals().
 *   4. SchedulerService::canRunNow() verhindert, dass der Pinger den
 *      Endpunkt schneller triggert als das konfigurierte Mindest-Intervall.
 *
 * Audit: Jeder Lauf erzeugt eine Zeile in scheduler_runs (von SchedulerService).
 */
final class CronController extends BaseController
{
    private const RATE_LIMIT_ENDPOINT  = 'cron';
    private const RATE_LIMIT_MAX       = 6;
    private const RATE_LIMIT_WINDOW    = 60;

    public function __construct(
        private readonly SchedulerService $scheduler,
        private readonly SettingsRepository $settings,
        private readonly RateLimitService $rateLimit,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(Request $request, Response $response): Response
    {
        $ip = $this->clientIp($request);

        if (!$this->rateLimit->isAllowed($ip, self::RATE_LIMIT_ENDPOINT, self::RATE_LIMIT_MAX, self::RATE_LIMIT_WINDOW)) {
            $this->logger->warning('Cron-Endpunkt: Rate-Limit erreicht', ['ip' => $ip]);
            return $this->json($response, [
                'ok'     => false,
                'reason' => 'rate_limited',
            ], 429);
        }
        $this->rateLimit->recordAttempt($ip, self::RATE_LIMIT_ENDPOINT);

        $providedToken = trim($request->getHeaderLine('X-Cron-Token'));
        $storedHash    = (string) ($this->settings->getValue('cron_external_token_hash') ?? '');

        if ($storedHash === '' || $providedToken === '') {
            $this->logger->warning('Cron-Endpunkt: Token-Auth fehlgeschlagen', [
                'ip'              => $ip,
                'has_stored_hash' => $storedHash !== '',
                'has_token'       => $providedToken !== '',
            ]);
            return $this->json($response, [
                'ok'     => false,
                'reason' => 'unauthorized',
            ], 401);
        }

        $providedHash = hash('sha256', $providedToken);
        if (!hash_equals($storedHash, $providedHash)) {
            $this->logger->warning('Cron-Endpunkt: Token ungueltig', ['ip' => $ip]);
            return $this->json($response, [
                'ok'     => false,
                'reason' => 'unauthorized',
            ], 401);
        }

        if (!$this->scheduler->isEnabled()) {
            return $this->json($response, [
                'ok'     => false,
                'reason' => 'notifications_disabled',
            ], 503);
        }

        if (!$this->scheduler->canRunNow()) {
            return $this->json($response, [
                'ok'      => true,
                'skipped' => true,
                'reason'  => 'min_interval_not_reached',
            ], 200);
        }

        $result = $this->scheduler->runDue('external', $ip);
        $this->logger->info('Cron-Lauf abgeschlossen', [
            'ip'        => $ip,
            'run_id'    => $result['run_id'],
            'processed' => $result['processed'],
            'failed'    => $result['failed'],
        ]);

        return $this->json($response, [
            'ok'        => true,
            'run_id'    => $result['run_id'],
            'processed' => $result['processed'],
            'failed'    => $result['failed'],
        ], 200);
    }

    private function clientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        $ip = $serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
    }
}
