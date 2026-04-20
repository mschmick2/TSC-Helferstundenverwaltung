<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Jobs;

use App\Models\Event;
use App\Models\User;
use App\Repositories\EventOrganizerRepository;
use App\Repositories\EventRepository;
use App\Repositories\UserRepository;
use App\Services\Jobs\EventCompletionReminderHandler;
use App\Services\NotificationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class EventCompletionReminderHandlerTest extends TestCase
{
    private EventRepository&MockObject $events;
    private EventOrganizerRepository&MockObject $organizers;
    private UserRepository&MockObject $users;
    private NotificationService&MockObject $notifications;
    private LoggerInterface&MockObject $logger;
    private EventCompletionReminderHandler $handler;

    protected function setUp(): void
    {
        $this->events        = $this->createMock(EventRepository::class);
        $this->organizers    = $this->createMock(EventOrganizerRepository::class);
        $this->users         = $this->createMock(UserRepository::class);
        $this->notifications = $this->createMock(NotificationService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->handler = new EventCompletionReminderHandler(
            $this->events,
            $this->organizers,
            $this->users,
            $this->notifications,
            $this->logger,
        );
    }

    /** @test */
    public function fehlende_id_wirft_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->handler->handle([]);
    }

    /** @test */
    public function event_nicht_vorhanden_wird_geskippt(): void
    {
        $this->events->method('findById')->willReturn(null);
        $this->notifications->expects($this->never())->method('sendEventCompletionReminder');
        $this->handler->handle(['event_id' => 1]);
    }

    /** @test */
    public function abgeschlossenes_event_wird_geskippt(): void
    {
        $event = Event::fromArray(['id' => 1, 'status' => Event::STATUS_ABGESCHLOSSEN]);
        $this->events->method('findById')->willReturn($event);
        $this->notifications->expects($this->never())->method('sendEventCompletionReminder');

        $this->handler->handle(['event_id' => 1]);
    }

    /** @test */
    public function verschickt_an_alle_organisatoren(): void
    {
        $event = Event::fromArray([
            'id' => 1, 'status' => Event::STATUS_VEROEFFENTLICHT, 'created_by' => 99,
        ]);
        $this->events->method('findById')->willReturn($event);
        $this->organizers->method('listUserIdsForEvent')->willReturn([10, 20]);

        $u1 = User::fromArray(['id' => 10, 'email' => 'a@x', 'vorname' => 'A']);
        $u2 = User::fromArray(['id' => 20, 'email' => 'b@x', 'vorname' => 'B']);
        $this->users->method('findById')->willReturnCallback(
            fn(int $id) => $id === 10 ? $u1 : $u2
        );

        $this->notifications->expects($this->exactly(2))
            ->method('sendEventCompletionReminder');

        $this->handler->handle(['event_id' => 1]);
    }

    /** @test */
    public function ohne_organisatoren_wird_ersteller_benachrichtigt(): void
    {
        $event = Event::fromArray([
            'id' => 1, 'status' => Event::STATUS_VEROEFFENTLICHT, 'created_by' => 99,
        ]);
        $this->events->method('findById')->willReturn($event);
        $this->organizers->method('listUserIdsForEvent')->willReturn([]);

        $creator = User::fromArray(['id' => 99, 'email' => 'c@x', 'vorname' => 'C']);
        $this->users->method('findById')->with(99)->willReturn($creator);

        $this->notifications->expects($this->once())
            ->method('sendEventCompletionReminder')
            ->with('c@x', 'C', $event);

        $this->handler->handle(['event_id' => 1]);
    }
}
