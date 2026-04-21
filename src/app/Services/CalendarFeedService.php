<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\EventTask;

/**
 * Konvertiert Events in das FullCalendar-v6-Event-Objekt-Format.
 * https://fullcalendar.io/docs/event-object
 *
 * Farben-Logik:
 *   - Event-Status 'abgesagt'      -> Bootstrap danger (#dc3545), textColor #fff
 *   - Event-Status 'abgeschlossen' -> Bootstrap secondary (#6c757d), textColor #fff
 *   - sonst: Farbe der ersten Task-Kategorie (sort_order ASC) wenn vorhanden
 *     -> Fallback Bootstrap primary (#0d6efd), textColor berechnet aus Luminanz
 */
final class CalendarFeedService
{
    private const COLOR_CANCELLED = '#dc3545';
    private const COLOR_DONE      = '#6c757d';
    private const COLOR_DEFAULT   = '#0d6efd';

    /**
     * Wandelt Events in FullCalendar-Events um.
     *
     * @param Event[] $events
     * @param array<int,string> $categoryColorByEventId event_id -> hex-color
     *        (vom Controller vorab aggregiert; leeres Array = keine Kategorie-Farben)
     * @param string $basePath z.B. "/helferstunden" (fuer Link zu Event-Detail)
     * @return array<int,array<string,mixed>>
     */
    public function buildEventsFeed(array $events, array $categoryColorByEventId, string $basePath): array
    {
        $feed = [];
        foreach ($events as $event) {
            $feed[] = $this->toFullCalendarEvent($event, $categoryColorByEventId, $basePath);
        }
        return $feed;
    }

    /**
     * Variante fuer eigene Teilnahmen: Titel erhaelt einen "[Teilnahme]"-Prefix,
     * sodass User im selben Kalender-Feed Events und eigene Assignments unterscheiden.
     *
     * @param Event[] $events   (bereits gefilterte Events, die User als Assignment hat)
     * @param array<int,string> $categoryColorByEventId
     */
    public function buildMyAssignmentsFeed(array $events, array $categoryColorByEventId, string $basePath): array
    {
        $feed = [];
        foreach ($events as $event) {
            $entry = $this->toFullCalendarEvent($event, $categoryColorByEventId, $basePath);
            $entry['title'] = '🎯 ' . $entry['title'];
            $entry['extendedProps']['is_assignment'] = true;
            $feed[] = $entry;
        }
        return $feed;
    }

    /**
     * @return array<string,mixed>
     */
    private function toFullCalendarEvent(Event $event, array $categoryColorByEventId, string $basePath): array
    {
        $color = match ($event->getStatus()) {
            Event::STATUS_ABGESAGT        => self::COLOR_CANCELLED,
            Event::STATUS_ABGESCHLOSSEN   => self::COLOR_DONE,
            default => $categoryColorByEventId[(int) $event->getId()] ?? self::COLOR_DEFAULT,
        };

        return [
            'id'        => (string) $event->getId(),
            'title'     => $event->getTitle(),
            'start'     => $this->toIso($event->getStartAt()),
            'end'       => $this->toIso($event->getEndAt()),
            'url'       => rtrim($basePath, '/') . '/events/' . (int) $event->getId(),
            'color'     => $color,
            'textColor' => $this->computeTextColor($color),
            'extendedProps' => [
                'status'   => $event->getStatus(),
                'location' => $event->getLocation(),
            ],
        ];
    }

    /**
     * MySQL DATETIME -> ISO 8601 mit T-Separator (FullCalendar-tauglich).
     * Beispiel: "2026-04-20 10:00:00" -> "2026-04-20T10:00:00"
     */
    private function toIso(string $mysqlDatetime): string
    {
        return str_replace(' ', 'T', $mysqlDatetime);
    }

    /**
     * Waehlt schwarz oder weiss als Text-Farbe, abhaengig von Luminanz der Hintergrundfarbe.
     * Heuristik: W3C-naeherungsweise.
     */
    private function computeTextColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return '#ffffff';
        }
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        return $luminance > 0.6 ? '#000000' : '#ffffff';
    }
}
