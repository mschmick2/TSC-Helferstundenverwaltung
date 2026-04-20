<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Event;
use App\Models\EventTask;
use App\Services\EmailService;
use App\Services\NotificationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Smoke-Tests fuer NotificationService.
 *
 * Verifiziert pro Notification-Typ:
 *   - Subject und HTML-Body werden korrekt zusammengebaut
 *   - User-Input wird XSS-sicher escaped (htmlspecialchars)
 *   - Die generierte URL nutzt den injizierten baseUrl
 *   - Rueckgabewert wird vom EmailService durchgereicht
 */
class NotificationServiceTest extends TestCase
{
    private const BASE_URL = 'https://vaes.example.com';

    private EmailService $emailSpy;
    private NotificationService $service;

    protected function setUp(): void
    {
        $this->emailSpy = new class extends EmailService {
            /** @var array<int, array{to: string, subject: string, html: string}> */
            public array $calls = [];
            public bool $returnValue = true;

            public function __construct()
            {
                parent::__construct([], new NullLogger());
            }

            public function send(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
            {
                $this->calls[] = [
                    'to'      => $to,
                    'subject' => $subject,
                    'html'    => $htmlBody,
                ];
                return $this->returnValue;
            }
        };

        $this->service = new NotificationService($this->emailSpy, self::BASE_URL);
    }

    private function makeEvent(int $id = 42, string $title = 'Sommerfest', ?string $location = 'Vereinsheim'): Event
    {
        return Event::fromArray([
            'id'       => $id,
            'title'    => $title,
            'location' => $location,
            'start_at' => '2026-06-15 14:00:00',
            'end_at'   => '2026-06-15 18:00:00',
            'status'   => Event::STATUS_VEROEFFENTLICHT,
        ]);
    }

    private function makeTask(string $title = 'Getraenkestand'): EventTask
    {
        return EventTask::fromArray([
            'id'       => 7,
            'event_id' => 42,
            'title'    => $title,
        ]);
    }

    // =========================================================================
    // sendEventReminder
    // =========================================================================

    public function test_sendEventReminder_buildsSubjectAndBody(): void
    {
        $this->service->sendEventReminder('user@example.com', 'Max', $this->makeEvent(), 1);

        $this->assertCount(1, $this->emailSpy->calls);
        $call = $this->emailSpy->calls[0];
        $this->assertSame('user@example.com', $call['to']);
        $this->assertStringContainsString('Sommerfest', $call['subject']);
        $this->assertStringContainsString('morgen', $call['subject']);
        $this->assertStringContainsString('Hallo Max', $call['html']);
        $this->assertStringContainsString('Vereinsheim', $call['html']);
        $this->assertStringContainsString('15.06.2026 14:00 Uhr', $call['html']);
        $this->assertStringContainsString(self::BASE_URL . '/events/42', $call['html']);
    }

    public function test_sendEventReminder_pluralHorizonForMultipleDays(): void
    {
        $this->service->sendEventReminder('user@example.com', 'Max', $this->makeEvent(), 7);

        $call = $this->emailSpy->calls[0];
        $this->assertStringContainsString('in 7 Tagen', $call['subject']);
        $this->assertStringContainsString('in 7 Tagen', $call['html']);
    }

    public function test_sendEventReminder_skipsLocationRowWhenEmpty(): void
    {
        $this->service->sendEventReminder('user@example.com', 'Max', $this->makeEvent(location: null), 1);

        $this->assertStringNotContainsString('Ort:', $this->emailSpy->calls[0]['html']);
    }

    // =========================================================================
    // XSS-Schutz: htmlspecialchars greift bei boesartigem Input
    // =========================================================================

    public function test_userInputIsEscapedInBody(): void
    {
        $payload = '<script>alert("xss")</script>';
        $this->service->sendEventReminder('user@example.com', $payload, $this->makeEvent(title: $payload), 1);

        $html = $this->emailSpy->calls[0]['html'];
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // =========================================================================
    // sendAssignmentInvite
    // =========================================================================

    public function test_sendAssignmentInvite_buildsSubjectAndBody(): void
    {
        $this->service->sendAssignmentInvite(
            'helfer@example.com',
            'Anna',
            $this->makeEvent(),
            $this->makeTask('Kuchenstand'),
        );

        $this->assertCount(1, $this->emailSpy->calls);
        $call = $this->emailSpy->calls[0];
        $this->assertSame('helfer@example.com', $call['to']);
        $this->assertStringContainsString('Neue Aufgabe', $call['subject']);
        $this->assertStringContainsString('Kuchenstand', $call['subject']);
        $this->assertStringContainsString('Hallo Anna', $call['html']);
        $this->assertStringContainsString('Sommerfest', $call['html']);
        $this->assertStringContainsString('Kuchenstand', $call['html']);
        $this->assertStringContainsString(self::BASE_URL . '/events/42', $call['html']);
    }

    // =========================================================================
    // sendAssignmentReminder
    // =========================================================================

    public function test_sendAssignmentReminder_buildsSubjectAndBody(): void
    {
        $this->service->sendAssignmentReminder(
            'helfer@example.com',
            'Anna',
            $this->makeEvent(),
            $this->makeTask(),
        );

        $call = $this->emailSpy->calls[0];
        $this->assertStringContainsString('Bitte Zusage bestaetigen', $call['subject']);
        $this->assertStringContainsString('Getraenkestand', $call['subject']);
        $this->assertStringContainsString('bestaetigen', $call['html']);
        $this->assertStringContainsString('Sommerfest', $call['html']);
    }

    // =========================================================================
    // sendDialogReminder
    // =========================================================================

    public function test_sendDialogReminder_singularDayWording(): void
    {
        $this->service->sendDialogReminder('user@example.com', 'Max', '2026-00042', 99, 1);

        $call = $this->emailSpy->calls[0];
        $this->assertStringContainsString('2026-00042', $call['subject']);
        $this->assertStringContainsString('1 Tag eine', $call['html']);
        $this->assertStringNotContainsString('1 Tagen', $call['html']);
        $this->assertStringContainsString(self::BASE_URL . '/entries/99', $call['html']);
    }

    public function test_sendDialogReminder_pluralDayWording(): void
    {
        $this->service->sendDialogReminder('user@example.com', 'Max', '2026-00042', 99, 5);

        $this->assertStringContainsString('5 Tagen', $this->emailSpy->calls[0]['html']);
    }

    // =========================================================================
    // sendEventCompletionReminder
    // =========================================================================

    public function test_sendEventCompletionReminder_usesEndAtForDate(): void
    {
        $this->service->sendEventCompletionReminder('orga@example.com', 'Tim', $this->makeEvent());

        $call = $this->emailSpy->calls[0];
        $this->assertStringContainsString('Bitte Veranstaltung abschliessen', $call['subject']);
        $this->assertStringContainsString('Sommerfest', $call['subject']);
        $this->assertStringContainsString('15.06.2026 18:00 Uhr', $call['html']);
        $this->assertStringContainsString(self::BASE_URL . '/events/42', $call['html']);
    }

    // =========================================================================
    // Rueckgabewert wird durchgereicht
    // =========================================================================

    public function test_returnValueFromEmailServiceIsPropagated(): void
    {
        $this->assertTrue(
            $this->service->sendEventReminder('user@example.com', 'Max', $this->makeEvent(), 1)
        );

        $this->emailSpy->returnValue = false;
        $this->assertFalse(
            $this->service->sendEventReminder('user@example.com', 'Max', $this->makeEvent(), 1)
        );
    }
}
