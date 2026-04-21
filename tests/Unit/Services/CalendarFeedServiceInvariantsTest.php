<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Services\CalendarFeedService;
use PHPUnit\Framework\TestCase;

/**
 * Invarianten fuer CalendarFeedService (Modul 6 I5).
 *
 * Prueft dass das FullCalendar-JSON-Format eingehalten wird:
 *   - Pflichtfelder id, title, start, end, color, textColor
 *   - ISO-8601-Datumsformat (mit T-Separator, ohne TZ — FullCalendar legt die im Viewer fest)
 *   - Farbwahl: Status ueberstimmt Kategorie-Color, Kategorie ueberstimmt Default
 *   - url enthaelt basePath-Praefix
 */
final class CalendarFeedServiceInvariantsTest extends TestCase
{
    private CalendarFeedService $svc;

    protected function setUp(): void
    {
        $this->svc = new CalendarFeedService();
    }

    private function event(int $id, string $status = Event::STATUS_VEROEFFENTLICHT): Event
    {
        return Event::fromArray([
            'id' => $id,
            'title' => "Event $id",
            'start_at' => '2026-06-15 10:00:00',
            'end_at'   => '2026-06-15 14:00:00',
            'status' => $status,
            'location' => 'Vereinsheim',
            'created_by' => 1,
        ]);
    }

    public function test_feed_has_required_fullcalendar_fields(): void
    {
        $feed = $this->svc->buildEventsFeed([$this->event(1)], [], '/helferstunden');
        self::assertCount(1, $feed);
        foreach (['id', 'title', 'start', 'end', 'color', 'textColor', 'url', 'extendedProps'] as $key) {
            self::assertArrayHasKey($key, $feed[0], "Feld '$key' fehlt im Feed.");
        }
    }

    public function test_dates_are_iso_with_t_separator(): void
    {
        $feed = $this->svc->buildEventsFeed([$this->event(1)], [], '');
        self::assertSame('2026-06-15T10:00:00', $feed[0]['start']);
        self::assertSame('2026-06-15T14:00:00', $feed[0]['end']);
    }

    public function test_status_abgesagt_uses_danger_color(): void
    {
        $feed = $this->svc->buildEventsFeed(
            [$this->event(1, Event::STATUS_ABGESAGT)],
            [1 => '#00ff00'], // Kategorie-Color wird von Status-Color ueberschrieben
            ''
        );
        self::assertSame('#dc3545', $feed[0]['color']);
    }

    public function test_status_abgeschlossen_uses_secondary_color(): void
    {
        $feed = $this->svc->buildEventsFeed(
            [$this->event(1, Event::STATUS_ABGESCHLOSSEN)],
            [],
            ''
        );
        self::assertSame('#6c757d', $feed[0]['color']);
    }

    public function test_category_color_overrides_default_for_published_events(): void
    {
        $feed = $this->svc->buildEventsFeed(
            [$this->event(7, Event::STATUS_VEROEFFENTLICHT)],
            [7 => '#abcdef'],
            ''
        );
        self::assertSame('#abcdef', $feed[0]['color']);
    }

    public function test_default_color_when_no_category(): void
    {
        $feed = $this->svc->buildEventsFeed([$this->event(1)], [], '');
        self::assertSame('#0d6efd', $feed[0]['color']);
    }

    public function test_url_contains_basepath(): void
    {
        $feed = $this->svc->buildEventsFeed([$this->event(42)], [], '/helferstunden');
        self::assertSame('/helferstunden/events/42', $feed[0]['url']);
    }

    public function test_my_assignments_feed_prefixes_title(): void
    {
        $feed = $this->svc->buildMyAssignmentsFeed([$this->event(1)], [], '');
        self::assertStringContainsString('🎯', $feed[0]['title']);
        self::assertTrue($feed[0]['extendedProps']['is_assignment'] ?? false);
    }

    public function test_text_color_high_contrast(): void
    {
        // Helle Farbe -> schwarzer Text
        $feedLight = $this->svc->buildEventsFeed(
            [$this->event(1)],
            [1 => '#ffff00'], // gelb (hohe Luminanz)
            ''
        );
        self::assertSame('#000000', $feedLight[0]['textColor']);

        // Dunkle Farbe -> weisser Text
        $feedDark = $this->svc->buildEventsFeed(
            [$this->event(2)],
            [2 => '#1a1a1a'],
            ''
        );
        self::assertSame('#ffffff', $feedDark[0]['textColor']);
    }
}
