<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Anzeige-DTO fuer eine aktive Edit-Session.
 *
 * Kombiniert die Session-Eigenschaften mit Vorname und Nachname des
 * Nutzers (JOIN auf users in EditSessionRepository::findActiveByEventId).
 * Der Controller serialisiert diese DTO direkt in den Polling-Response —
 * kein zweiter User-Lookup noetig.
 *
 * Die Anzeige nutzt "Vorname Nachname", bewusst ohne Rollen-Label
 * (Architect-C4 aus I7e-C G1: keine Rollen-Hierarchie in der UX-Info).
 *
 * Eingefuehrt in Modul 6 I7e-C.1 Phase 1.
 */
final class EditSessionView
{
    public function __construct(
        private readonly int $sessionId,
        private readonly int $userId,
        private readonly string $vorname,
        private readonly string $nachname,
        private readonly string $startedAt,
        private readonly string $lastSeenAt,
        private readonly int $durationSeconds,
    ) {
    }

    public function getSessionId(): int
    {
        return $this->sessionId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getVorname(): string
    {
        return $this->vorname;
    }

    public function getNachname(): string
    {
        return $this->nachname;
    }

    public function getDisplayName(): string
    {
        $name = trim($this->vorname . ' ' . $this->nachname);
        return $name !== '' ? $name : 'Unbekannter Nutzer';
    }

    public function getStartedAt(): string
    {
        return $this->startedAt;
    }

    public function getLastSeenAt(): string
    {
        return $this->lastSeenAt;
    }

    public function getDurationSeconds(): int
    {
        return $this->durationSeconds;
    }

    /**
     * Formt eine Liste von EditSessionView-DTOs in JSON-taugliche Arrays.
     * Filtert die Session des aktuellen Viewers heraus und dedupliziert
     * pro user_id (Multi-Tab desselben Users wird zu einem Eintrag
     * zusammengefasst -- R2 aus I7e-C G1).
     *
     * Wird sowohl vom API-Controller als auch von den Editor-Views
     * (Initial-State-Rendering, C4) verwendet.
     *
     * @param EditSessionView[] $views
     * @return array<int, array<string, mixed>>
     */
    public static function toJsonReadyArray(array $views, int $viewerUserId): array
    {
        $out = [];
        $seenUserIds = [];
        foreach ($views as $view) {
            $uid = $view->getUserId();
            if ($uid === $viewerUserId) {
                continue;
            }
            if (isset($seenUserIds[$uid])) {
                continue;
            }
            $seenUserIds[$uid] = true;

            $out[] = [
                'id'               => $view->getSessionId(),
                'user_id'          => $uid,
                'display_name'     => $view->getDisplayName(),
                'started_at'       => $view->getStartedAt(),
                'last_seen_at'     => $view->getLastSeenAt(),
                'duration_seconds' => $view->getDurationSeconds(),
            ];
        }
        return $out;
    }
}
