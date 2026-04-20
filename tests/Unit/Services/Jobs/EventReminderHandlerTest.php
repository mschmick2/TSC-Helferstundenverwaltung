<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Jobs;

use App\Models\Event;
use App\Models\EventTaskAssignment;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\EventTaskAssignmentRepository;
use App\Repositories\UserRepository;
use App\Services\Jobs\EventReminderHandler;
use App\Services\NotificationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class EventReminderHandlerTest extends TestCase
{
    private EventRepository&MockObject $events;
    private EventTaskAssignmentRepository&MockObject $assignments;
    private UserRepository&MockObject $users;
    private NotificationService&MockObject $notifications;
    private LoggerInterface&MockObject $logger;
    private EventReminderHandler $handler;

    protected function setUp(): void
    {
        $this->events        = $this->createMock(EventRepository::class);
        $this->assignments   = $this->createMock(EventTaskAssignmentRepository::class);
        $this->users         = $this->createMock(UserRepository::class);
        $this->notifications = $this->createMock(NotificationService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->handler = new EventReminderHandler(
            $this->events,
            $this->assignments,
            $this->users,
            $this->notifications,
            $this->logger,
        );
    }

    /** @test */
    public function fehlende_event_id_wirft_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->handler->handle([]);
    }

    /** @test */
    public function event_nicht_vorhanden_wird_geskippt(): void
    {
        $this->events->method('findById')->willReturn(null);
        $this->notifications->expects($this->never())->method('sendEventReminder');

        $this->handler->handle(['event_id' => 99, 'days_before' => 1]);
    }

    /** @test */
    public function abgesagtes_event_wird_geskippt(): void
    {
        $event = Event::fromArray(['id' => 1, 'status' => Event::STATUS_ABGESAGT]);
        $this->events->method('findById')->willReturn($event);
        $this->notifications->expects($this->never())->method('sendEventReminder');

        $this->handler->handle(['event_id' => 1, 'days_before' => 1]);
    }

    /** @test */
    public function verschickt_reminder_an_alle_bestaetigten_helfer(): void
    {
        $event = Event::fromArray([
            'id' => 1,
            'status' => Event::STATUS_VEROEFFENTLICHT,
            'title' => 'Sommerfest',
        ]);
        $this->events->method('findById')->willReturn($event);

        $a1 = EventTaskAssignment::fromArray(['id' => 10, 'user_id' => 100, 'status' => EventTaskAssignment::STATUS_BESTAETIGT]);
        $a2 = EventTaskAssignment::fromArray(['id' => 11, 'user_id' => 200, 'status' => EventTaskAssignment::STATUS_BESTAETIGT]);
        $this->assignments->method('findConfirmedForEvent')->willReturn([$a1, $a2]);

        $u1 = User::fromArray(['id' => 100, 'email' => 'a@x', 'vorname' => 'Alice']);
        $u2 = User::fromArray(['id' => 200, 'email' => 'b@x', 'vorname' => 'Bob']);
        $this->users->method('findById')->willReturnCallback(
            fn(int $id) => $id === 100 ? $u1 : $u2
        );

        $this->notifications->expects($this->exactly(2))
            ->method('sendEventReminder')
            ->with($this->logicalOr('a@x', 'b@x'), $this->anything(), $event, 7);

        $this->handler->handle(['event_id' => 1, 'days_before' => 7]);
    }

    /** @test */
    public function user_ohne_email_wird_uebersprungen(): void
    {
        $event = Event::fromArray(['id' => 1, 'status' => Event::STATUS_VEROEFFENTLICHT]);
        $this->events->method('findById')->willReturn($event);

        $a = EventTaskAssignment::fromArray(['id' => 10, 'user_id' => 100, 'status' => EventTaskAssignment::STATUS_BESTAETIGT]);
        $this->assignments->method('findConfirmedForEvent')->willReturn([$a]);

        $u = User::fromArray(['id' => 100, 'email' => '', 'vorname' => 'Alice']);
        $this->users->method('findById')->willReturn($u);

        $this->notifications->expects($this->never())->method('sendEventReminder');

        $this->handler->handle(['event_id' => 1, 'days_before' => 1]);
    }
}
