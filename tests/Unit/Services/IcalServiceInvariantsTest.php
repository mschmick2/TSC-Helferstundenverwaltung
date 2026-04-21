<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Services\IcalService;
use PHPUnit\Framework\TestCase;

/**
 * Invarianten fuer IcalService (Modul 6 I5).
 *
 * Prueft RFC-5545-Compliance auf Ebene der generierten ICS-Strings:
 *   - CRLF-Line-Endings
 *   - BEGIN:VCALENDAR / END:VCALENDAR Wrapper
 *   - VTIMEZONE-Block mit TZID=Europe/Berlin
 *   - DTSTART/DTEND mit TZID
 *   - UID stabil pro Event-ID
 *   - Escaping (Komma, Semikolon)
 *   - Zeilen-Folding bei >75 Oktetten
 */
final class IcalServiceInvariantsTest extends TestCase
{
    private IcalService $service;

    protected function setUp(): void
    {
        $this->service = new IcalService();
    }

    private function buildEvent(array $overrides = []): Event
    {
        return Event::fromArray(array_merge([
            'id' => 42,
            'title' => 'Smoke Event',
            'description' => null,
            'location' => null,
            'start_at' => '2026-06-15 10:00:00',
            'end_at'   => '2026-06-15 14:00:00',
            'status' => Event::STATUS_VEROEFFENTLICHT,
            'created_by' => 1,
        ], $overrides));
    }

    public function test_output_uses_crlf_line_endings(): void
    {
        $ics = $this->service->renderEvent($this->buildEvent());
        self::assertStringContainsString("\r\n", $ics);
        // Keine alleinstehenden LFs: jeder LF muss von CR gefolgt sein (bzw. davor haben)
        $lfCount = substr_count($ics, "\n");
        $crlfCount = substr_count($ics, "\r\n");
        self::assertSame($lfCount, $crlfCount, 'Alle LF muessen CRLF sein (RFC 5545 §3.1).');
    }

    public function test_output_wraps_in_vcalendar(): void
    {
        $ics = $this->service->renderEvent($this->buildEvent());
        self::assertStringContainsString("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringContainsString("END:VCALENDAR\r\n", $ics);
        self::assertStringContainsString('VERSION:2.0', $ics);
        self::assertStringContainsString('PRODID:', $ics);
    }

    public function test_vtimezone_block_present_with_europe_berlin(): void
    {
        $ics = $this->service->renderEvent($this->buildEvent());
        self::assertStringContainsString('BEGIN:VTIMEZONE', $ics);
        self::assertStringContainsString('TZID:Europe/Berlin', $ics);
        self::assertStringContainsString('END:VTIMEZONE', $ics);
    }

    public function test_dtstart_dtend_use_tzid(): void
    {
        $ics = $this->service->renderEvent($this->buildEvent());
        self::assertStringContainsString('DTSTART;TZID=Europe/Berlin:20260615T100000', $ics);
        self::assertStringContainsString('DTEND;TZID=Europe/Berlin:20260615T140000', $ics);
    }

    public function test_uid_is_stable_per_event_id(): void
    {
        $ics1 = $this->service->renderEvent($this->buildEvent(['id' => 42]));
        $ics2 = $this->service->renderEvent($this->buildEvent(['id' => 42]));
        // UIDs muessen identisch sein (Event-ID-basiert), unabhaengig von DTSTAMP
        preg_match('/UID:(\S+)/', $ics1, $m1);
        preg_match('/UID:(\S+)/', $ics2, $m2);
        self::assertSame($m1[1] ?? '', $m2[1] ?? '');
        self::assertStringContainsString('event-42', $m1[1] ?? '');
    }

    public function test_cancelled_status_mapped_to_cancelled(): void
    {
        $ics = $this->service->renderEvent($this->buildEvent(['status' => Event::STATUS_ABGESAGT]));
        self::assertStringContainsString('STATUS:CANCELLED', $ics);
    }

    public function test_text_fields_are_escaped(): void
    {
        $ics = $this->service->renderEvent($this->buildEvent([
            'title' => 'Test, mit; Kommas und Semikolons',
            'description' => "Mehrzeilig\nzweite Zeile",
        ]));
        self::assertStringContainsString('Test\\, mit\\; Kommas und Semikolons', $ics);
        self::assertStringContainsString('Mehrzeilig\\nzweite Zeile', $ics);
    }

    public function test_long_lines_are_folded_at_75_octets(): void
    {
        $longTitle = str_repeat('X', 200);
        $ics = $this->service->renderEvent($this->buildEvent(['title' => $longTitle]));
        // Folding: CRLF + SPACE als Fortsetzung. Nach einem CRLF darf kein Content-Zeile
        // mit > 75 Zeichen stehen (ausser ASCII-Byte-Laenge).
        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(
                75,
                strlen($line),
                'Keine Zeile darf 75 Oktette ueberschreiten (RFC 5545 §3.1).'
            );
        }
    }

    public function test_generate_token_returns_64_hex_chars(): void
    {
        $token = IcalService::generateToken();
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }

    public function test_feed_contains_multiple_vevents(): void
    {
        $events = [
            $this->buildEvent(['id' => 1, 'title' => 'One']),
            $this->buildEvent(['id' => 2, 'title' => 'Two']),
        ];
        $ics = $this->service->renderFeed($events, 'Mein VAES-Kalender');
        self::assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
        self::assertSame(2, substr_count($ics, 'END:VEVENT'));
        self::assertStringContainsString('X-WR-CALNAME:Mein VAES-Kalender', $ics);
    }
}
