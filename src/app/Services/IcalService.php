<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;

/**
 * iCal-Export nach RFC 5545 (Modul 6 I5).
 *
 *   - renderEvent()        : einzelnes Event als .ics-Response
 *   - renderFeed()         : kompletter Feed fuer /ical/subscribe/{token}
 *
 * Wichtig:
 *   - CRLF-Line-Endings (RFC 5545 §3.1)
 *   - Zeilen max 75 Oktette, danach Folding (\r\n + Leerzeichen)
 *   - Text-Values escapen (\\n, \\,, \\;)
 *   - DTSTART/DTEND mit TZID=Europe/Berlin (VTIMEZONE-Block hardcoded,
 *     deckt aktuelle EU-Sommerzeit-Regelung ab)
 *   - UID eindeutig + stabil, damit Kalender-Clients Updates erkennen
 */
final class IcalService
{
    private const CRLF = "\r\n";
    private const TZID = 'Europe/Berlin';

    /** VTIMEZONE-Block fuer Europe/Berlin (CET/CEST) gemaess EU-DST-Regeln.
     *  Hardcoded statt dynamisch generiert — stabiler ueber PHP-Versionen. */
    private const VTIMEZONE_BERLIN = <<<'ICS'
BEGIN:VTIMEZONE
TZID:Europe/Berlin
BEGIN:STANDARD
DTSTART:19701025T030000
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
TZNAME:CET
RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU
END:STANDARD
BEGIN:DAYLIGHT
DTSTART:19700329T020000
TZOFFSETFROM:+0100
TZOFFSETTO:+0200
TZNAME:CEST
RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU
END:DAYLIGHT
END:VTIMEZONE
ICS;

    public function __construct(private readonly string $productIdentifier = '-//TSC Mondial//VAES//DE')
    {
    }

    /**
     * Einzelnes Event als vollstaendiges iCal-Dokument.
     */
    public function renderEvent(Event $event): string
    {
        return $this->wrap([$this->buildVevent($event)]);
    }

    /**
     * Mehrere Events (z.B. Subscription-Feed fuer einen User) in einem Dokument.
     *
     * @param Event[] $events
     */
    public function renderFeed(array $events, ?string $calendarName = null): string
    {
        $veventBlocks = [];
        foreach ($events as $event) {
            $veventBlocks[] = $this->buildVevent($event);
        }
        return $this->wrap($veventBlocks, $calendarName);
    }

    /**
     * Wrappt VEVENT(s) in VCALENDAR mit VTIMEZONE-Block.
     */
    private function wrap(array $veventBlocks, ?string $calendarName = null): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . $this->productIdentifier,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];
        if ($calendarName !== null && $calendarName !== '') {
            $lines[] = 'X-WR-CALNAME:' . $this->escapeText($calendarName);
            $lines[] = 'X-WR-TIMEZONE:' . self::TZID;
        }

        // VTIMEZONE-Konstante nutzt die Line-Endings der Quellcode-Datei; auf LF-
        // basierten Checkouts (z.B. Git-Auto-CRLF=false) waere das ein RFC-5545-Verstoss.
        // Deshalb normalisieren: \r\n|\r|\n -> \r\n.
        $vtimezone = preg_replace('/\r\n|\r|\n/', self::CRLF, self::VTIMEZONE_BERLIN) ?? self::VTIMEZONE_BERLIN;

        $output = implode(self::CRLF, $lines) . self::CRLF
            . $vtimezone . self::CRLF
            . implode(self::CRLF, $veventBlocks) . self::CRLF
            . 'END:VCALENDAR' . self::CRLF;

        return $this->foldLines($output);
    }

    /**
     * Baut einen VEVENT-Block fuer ein Event (ohne foldLines — das macht wrap()).
     */
    private function buildVevent(Event $event): string
    {
        $tz = new DateTimeZone(self::TZID);
        $start = new DateTimeImmutable($event->getStartAt(), $tz);
        $end   = new DateTimeImmutable($event->getEndAt(), $tz);
        $now   = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $uid = sprintf('event-%d@vaes.tsc-mondial.local', (int) $event->getId());
        $status = $event->getStatus() === Event::STATUS_ABGESAGT ? 'CANCELLED' : 'CONFIRMED';

        $parts = [
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $now->format('Ymd\THis\Z'),
            'DTSTART;TZID=' . self::TZID . ':' . $start->format('Ymd\THis'),
            'DTEND;TZID='   . self::TZID . ':' . $end->format('Ymd\THis'),
            'SUMMARY:' . $this->escapeText($event->getTitle()),
            'STATUS:' . $status,
        ];

        $description = $event->getDescription();
        if ($description !== null && $description !== '') {
            $parts[] = 'DESCRIPTION:' . $this->escapeText($description);
        }

        $location = $event->getLocation();
        if ($location !== null && $location !== '') {
            $parts[] = 'LOCATION:' . $this->escapeText($location);
        }

        // Sequence-Nummer = Anzahl Updates; hier vereinfacht 0 (ohne extra-Feld).
        // Clients erkennen Aenderungen ueber UID+LAST-MODIFIED (DTSTAMP).
        $parts[] = 'END:VEVENT';

        return implode(self::CRLF, $parts);
    }

    /**
     * RFC 5545 §3.3.11: TEXT-Value escapen.
     * Reihenfolge wichtig: Backslash zuerst, dann Komma/Semikolon/Newlines.
     */
    private function escapeText(string $value): string
    {
        $value = str_replace("\\", "\\\\", $value);
        $value = str_replace([',', ';'], ['\\,', '\\;'], $value);
        $value = preg_replace('/\r\n|\r|\n/', '\\n', $value) ?? $value;
        return $value;
    }

    /**
     * RFC 5545 §3.1: Zeilen > 75 Oktette foldern. Fortsetzungszeilen beginnen
     * mit einem Whitespace (SPACE oder TAB). Wir nutzen SPACE.
     *
     * Achtung: "Oktette" = Bytes, nicht Zeichen. Bei UTF-8 muss bytewise
     * gefaltet werden, aber nicht mitten in einem Multibyte-Zeichen.
     */
    private function foldLines(string $content): string
    {
        $outLines = [];
        foreach (preg_split('/\r\n/', $content) ?: [] as $line) {
            if (strlen($line) <= 75) {
                $outLines[] = $line;
                continue;
            }
            // Erste Zeile: max 75 Oktetten Inhalt.
            // Fortsetzungen: 1 Byte SPACE-Prefix + max 74 Oktetten Inhalt = 75 gesamt.
            $first = $this->cutAtUtf8Boundary($line, 75);
            $outLines[] = substr($line, 0, $first);
            $remaining = substr($line, $first);
            while ($remaining !== '' && $remaining !== false) {
                $cut = $this->cutAtUtf8Boundary($remaining, 74);
                $outLines[] = ' ' . substr($remaining, 0, $cut);
                $remaining = substr($remaining, $cut);
            }
        }
        return implode(self::CRLF, $outLines);
    }

    /**
     * Findet den groessten Cut-Index <= $max, der nicht mitten in einem
     * UTF-8-Multibyte-Zeichen liegt. Fallback: $max, falls Input < $max ist.
     */
    private function cutAtUtf8Boundary(string $s, int $max): int
    {
        $len = strlen($s);
        if ($len <= $max) {
            return $len;
        }
        $cut = $max;
        while ($cut > 0 && (ord($s[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }
        return $cut;
    }

    /**
     * Neuen Subscribe-Token erzeugen (64 Hex-Zeichen, 256-bit Entropie).
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
