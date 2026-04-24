<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Geworfen, wenn ein UPDATE mit erwarteter Version fehlschlaegt, weil
 * die DB-Zeile zwischenzeitlich von einem anderen Request geaendert
 * wurde (Optimistic-Locking-Konflikt).
 *
 * Konventionen (Modul 6 I7e-B.1):
 *
 *  - Repository gibt `bool` zurueck (`true` = Update erfolgreich,
 *    `false` = entweder Version-Mismatch ODER Zeile fehlt / ist
 *    bereits soft-geloescht). Repository wirft diese Exception NICHT
 *    selbst — es unterscheidet nicht zwischen "Konflikt" und "nicht
 *    gefunden". Das ist Service-Aufgabe.
 *
 *  - TaskTreeService (und spaeter EventRepository-artige Services)
 *    faengt das `false` aus dem Repo ab. Wenn der Aufrufer einen
 *    `?int $expectedVersion !== null` mitgegeben hat, wirft der
 *    Service diese `OptimisticLockException` mit Kontext
 *    (`entityId`, `expectedVersion`).
 *
 *  - Controller faengt die Exception und rendert 409 Conflict mit
 *    JSON-Payload (Tree-Editor-JS, XHR) oder Flash + Redirect
 *    (klassische Views). Diese Uebersetzung ist I7e-B.1 Phase 2.
 *
 *  - Der Bestand-Eintrag `EventRepository::update` (Modul 7 I3) nutzt
 *    bool-Rueckgabe ohne Exception — dort kommt der Konflikt direkt
 *    im Controller an. Hier haben wir einen Service dazwischen; die
 *    Exception ist konsistenter mit dem Service-Exception-Pattern
 *    (`ValidationException`, `BusinessRuleException`).
 */
class OptimisticLockException extends RuntimeException
{
    public function __construct(
        private readonly int $entityId,
        private readonly int $expectedVersion,
        private readonly string $entityKind = 'event_task'
    ) {
        parent::__construct(sprintf(
            'Optimistic lock conflict on %s id=%d, expected version=%d',
            $entityKind,
            $entityId,
            $expectedVersion
        ));
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function getExpectedVersion(): int
    {
        return $this->expectedVersion;
    }

    public function getEntityKind(): string
    {
        return $this->entityKind;
    }
}
