<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\EntryLockRepository;
use App\Repositories\UserRepository;

/**
 * Fassade vor EntryLockRepository fuer Modul 7 I1.
 *
 * Liest den TTL aus dem Setting 'lock_timeout_minutes' (Default 5) und
 * liefert strukturierte Ergebnisse an den Controller. Wirft im Normalfall
 * keine Exception — der Controller entscheidet, ob ein Konflikt zur
 * Read-Only-Ansicht oder zur 409-Response wird.
 */
final class EntryLockService
{
    public function __construct(
        private readonly EntryLockRepository $lockRepo,
        private readonly UserRepository $userRepo,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Versuch, den Lock zu setzen oder zu verlaengern.
     *
     * Rueckgabe:
     *   [
     *     'success'    => true,
     *     'lock'       => ['id'=>…, 'expires_at'=>'YYYY-MM-DD HH:MM:SS'],
     *   ]
     *   oder
     *   [
     *     'success'    => false,
     *     'held_by'    => ['user_id'=>…, 'name'=>'…', 'expires_at'=>'…'],
     *   ]
     *
     * @return array<string, mixed>
     */
    public function tryAcquire(int $entryId, int $userId, ?int $sessionId): array
    {
        $ttl = $this->getTtlMinutes();

        $acquired = $this->lockRepo->acquireOrRefresh($entryId, $userId, $sessionId, $ttl);

        if ($acquired !== null) {
            return [
                'success' => true,
                'lock'    => [
                    'id'         => (int) $acquired['id'],
                    'expires_at' => (string) $acquired['expires_at'],
                ],
            ];
        }

        $active = $this->lockRepo->findActive($entryId);

        if ($active === null) {
            // Race: zwischen acquire und findActive ist der Lock abgelaufen.
            // Ein zweiter Versuch hier ist mikrooptimierung — wir melden
            // den Konflikt trotzdem ehrlich, der User kann neu laden.
            return [
                'success' => false,
                'held_by' => [
                    'user_id'    => 0,
                    'name'       => 'Unbekannt',
                    'expires_at' => null,
                ],
            ];
        }

        $holder = $this->userRepo->findById((int) $active['user_id']);
        $name = $holder !== null
            ? trim($holder->getVorname() . ' ' . $holder->getNachname())
            : 'Unbekannt';

        return [
            'success' => false,
            'held_by' => [
                'user_id'    => (int) $active['user_id'],
                'name'       => $name === '' ? 'Unbekannt' : $name,
                'expires_at' => (string) $active['expires_at'],
            ],
        ];
    }

    /**
     * Eigenen Lock loeschen. Rueckgabe: true, wenn eine Zeile entfernt wurde.
     */
    public function release(int $entryId, int $userId): bool
    {
        return $this->lockRepo->releaseByUser($entryId, $userId) > 0;
    }

    /**
     * Reiner Status-Check ohne Lock-Uebernahme. Fuer Read-Only-Clients,
     * die periodisch pruefen, ob der Lock frei geworden ist.
     *
     * Rueckgabe:
     *   ['held_by_other' => true,  'name' => '…', 'expires_at' => '…']
     *   ['held_by_other' => false]
     *
     * @return array<string, mixed>
     */
    public function checkStatus(int $entryId, int $userId): array
    {
        $active = $this->lockRepo->findActive($entryId);

        if ($active === null) {
            return ['held_by_other' => false];
        }

        $holderId = (int) $active['user_id'];
        if ($holderId === $userId) {
            return ['held_by_other' => false];
        }

        $holder = $this->userRepo->findById($holderId);
        $name = $holder !== null
            ? trim($holder->getVorname() . ' ' . $holder->getNachname())
            : 'Unbekannt';

        return [
            'held_by_other' => true,
            'name'          => $name === '' ? 'Unbekannt' : $name,
            'expires_at'    => (string) $active['expires_at'],
        ];
    }

    /**
     * Liefert den aktuellen TTL in Minuten.
     */
    public function getTtlMinutes(): int
    {
        $minutes = $this->settings->getInt('lock_timeout_minutes', 5);
        return $minutes > 0 ? $minutes : 5;
    }

    /**
     * Haushalt: Gibt die Anzahl geloeschter abgelaufener Locks zurueck.
     */
    public function cleanupStale(): int
    {
        return $this->lockRepo->deleteStale();
    }
}
