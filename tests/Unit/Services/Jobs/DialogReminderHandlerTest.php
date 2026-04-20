<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Jobs;

use App\Models\User;
use App\Models\WorkEntry;
use App\Repositories\DialogRepository;
use App\Repositories\UserRepository;
use App\Repositories\WorkEntryRepository;
use App\Services\Jobs\DialogReminderHandler;
use App\Services\NotificationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class DialogReminderHandlerTest extends TestCase
{
    private WorkEntryRepository&MockObject $entries;
    private DialogRepository&MockObject $dialogs;
    private UserRepository&MockObject $users;
    private NotificationService&MockObject $notifications;
    private LoggerInterface&MockObject $logger;
    private DialogReminderHandler $handler;

    protected function setUp(): void
    {
        $this->entries       = $this->createMock(WorkEntryRepository::class);
        $this->dialogs       = $this->createMock(DialogRepository::class);
        $this->users         = $this->createMock(UserRepository::class);
        $this->notifications = $this->createMock(NotificationService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        $this->handler = new DialogReminderHandler(
            $this->entries,
            $this->dialogs,
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
    public function nicht_gefundener_entry_wird_geskippt(): void
    {
        $this->entries->method('findById')->willReturn(null);
        $this->notifications->expects($this->never())->method('sendDialogReminder');

        $this->handler->handle(['work_entry_id' => 99]);
    }

    /** @test */
    public function keine_offenen_fragen_wird_geskippt(): void
    {
        $entry = $this->createMock(WorkEntry::class);
        $this->entries->method('findById')->willReturn($entry);
        $this->dialogs->method('countOpenQuestions')->willReturn(0);
        $this->notifications->expects($this->never())->method('sendDialogReminder');

        $this->handler->handle(['work_entry_id' => 42]);
    }

    /** @test */
    public function verschickt_reminder_bei_offener_frage(): void
    {
        $entry = $this->createMock(WorkEntry::class);
        $entry->method('getUserId')->willReturn(100);
        $entry->method('getEntryNumber')->willReturn('2026-00042');
        $this->entries->method('findById')->willReturn($entry);
        $this->dialogs->method('countOpenQuestions')->willReturn(1);

        $user = User::fromArray(['id' => 100, 'email' => 'm@x', 'vorname' => 'Maya']);
        $this->users->method('findById')->willReturn($user);

        $this->notifications->expects($this->once())
            ->method('sendDialogReminder')
            ->with('m@x', 'Maya', '2026-00042', 42, 5);

        $this->handler->handle(['work_entry_id' => 42, 'days_open' => 5]);
    }
}
