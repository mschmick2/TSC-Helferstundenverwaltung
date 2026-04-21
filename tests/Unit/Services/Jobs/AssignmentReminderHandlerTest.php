<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Jobs;

use App\Models\Event;
use App\Models\EventTask;
use App\Models\EventTaskAssignment;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\EventTaskAssignmentRepository;
use App\Repositories\EventTaskRepository;
use App\Repositories\UserRepository;
use App\Services\Jobs\AssignmentReminderHandler;
use App\Services\NotificationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class AssignmentReminderHandlerTest extends TestCase
{
    private EventTaskAssignmentRepository&MockObject $assignments;
    private EventTaskRepository&MockObject $tasks;
    private EventRepository&MockObject $events;
    private UserRepository&MockObject $users;
    private NotificationService&MockObject $notifications;
    private LoggerInterface&MockObject $logger;
    private AssignmentReminderHandler $handler;

    protected function setUp(): void
    {
        $this->assignments   = $this->createMock(EventTaskAssignmentRepository::class);
        $this->tasks         = $this->createMock(EventTaskRepository::class);
        $this->events        = $this->createMock(EventRepository::class);
        $this->users         = $this->createMock(UserRepository::class);
        $this->notifications = $this->createMock(NotificationService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->handler = new AssignmentReminderHandler(
            $this->assignments,
            $this->tasks,
            $this->events,
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
    public function bestaetigt_wird_geskippt(): void
    {
        $a = EventTaskAssignment::fromArray([
            'id' => 1, 'task_id' => 7, 'user_id' => 100,
            'status' => EventTaskAssignment::STATUS_BESTAETIGT,
        ]);
        $this->assignments->method('findById')->willReturn($a);
        $this->notifications->expects($this->never())->method('sendAssignmentReminder');

        $this->handler->handle(['assignment_id' => 1]);
    }

    /** @test */
    public function finales_event_wird_geskippt(): void
    {
        $a = EventTaskAssignment::fromArray([
            'id' => 1, 'task_id' => 7, 'user_id' => 100,
            'status' => EventTaskAssignment::STATUS_VORGESCHLAGEN,
        ]);
        $task  = EventTask::fromArray(['id' => 7, 'event_id' => 3]);
        $event = Event::fromArray(['id' => 3, 'status' => Event::STATUS_ABGESCHLOSSEN]);
        $user  = User::fromArray(['id' => 100, 'email' => 'h@x', 'vorname' => 'H']);

        $this->assignments->method('findById')->willReturn($a);
        $this->tasks->method('findById')->willReturn($task);
        $this->events->method('findById')->willReturn($event);
        $this->users->method('findById')->willReturn($user);

        $this->notifications->expects($this->never())->method('sendAssignmentReminder');

        $this->handler->handle(['assignment_id' => 1]);
    }

    /** @test */
    public function verschickt_reminder_fuer_offenes_assignment(): void
    {
        $a = EventTaskAssignment::fromArray([
            'id' => 1, 'task_id' => 7, 'user_id' => 100,
            'status' => EventTaskAssignment::STATUS_VORGESCHLAGEN,
        ]);
        $task  = EventTask::fromArray(['id' => 7, 'event_id' => 3]);
        $event = Event::fromArray(['id' => 3, 'status' => Event::STATUS_VEROEFFENTLICHT]);
        $user  = User::fromArray(['id' => 100, 'email' => 'h@x', 'vorname' => 'H']);

        $this->assignments->method('findById')->willReturn($a);
        $this->tasks->method('findById')->willReturn($task);
        $this->events->method('findById')->willReturn($event);
        $this->users->method('findById')->willReturn($user);

        $this->notifications->expects($this->once())
            ->method('sendAssignmentReminder')
            ->with('h@x', 'H', $event, $task);

        $this->handler->handle(['assignment_id' => 1]);
    }
}
