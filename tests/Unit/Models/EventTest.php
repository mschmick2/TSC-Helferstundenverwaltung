<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Event;
use PHPUnit\Framework\TestCase;

final class EventTest extends TestCase
{
    public function test_fromArray_maps_all_fields(): void
    {
        $row = [
            'id' => 42,
            'title' => 'Sommerfest',
            'description' => 'Beschreibung',
            'location' => 'Vereinsheim',
            'start_at' => '2026-06-08 14:00:00',
            'end_at' => '2026-06-08 22:00:00',
            'status' => 'veroeffentlicht',
            'cancel_deadline_hours' => 48,
            'created_by' => 7,
            'created_at' => '2026-04-01 10:00:00',
            'updated_at' => '2026-04-02 11:00:00',
            'deleted_at' => null,
            'deleted_by' => null,
        ];

        $event = Event::fromArray($row);

        self::assertSame(42, $event->getId());
        self::assertSame('Sommerfest', $event->getTitle());
        self::assertSame('Vereinsheim', $event->getLocation());
        self::assertSame('veroeffentlicht', $event->getStatus());
        self::assertSame(48, $event->getCancelDeadlineHours());
        self::assertSame(7, $event->getCreatedBy());
        self::assertNull($event->getDeletedAt());
    }

    public function test_fromArray_applies_default_cancel_deadline(): void
    {
        $event = Event::fromArray(['title' => 'X', 'start_at' => '', 'end_at' => '']);
        self::assertSame(
            Event::DEFAULT_CANCEL_DEADLINE_HOURS,
            $event->getCancelDeadlineHours()
        );
    }

    public function test_isPublished_returns_true_only_for_veroeffentlicht(): void
    {
        $draft = Event::fromArray(['status' => Event::STATUS_ENTWURF, 'start_at' => '', 'end_at' => '']);
        self::assertFalse($draft->isPublished());

        $live = Event::fromArray(['status' => Event::STATUS_VEROEFFENTLICHT, 'start_at' => '', 'end_at' => '']);
        self::assertTrue($live->isPublished());

        $done = Event::fromArray(['status' => Event::STATUS_ABGESCHLOSSEN, 'start_at' => '', 'end_at' => '']);
        self::assertFalse($done->isPublished());
    }

    public function test_isFinal_true_only_for_abgeschlossen_or_abgesagt(): void
    {
        self::assertTrue(
            Event::fromArray(['status' => Event::STATUS_ABGESCHLOSSEN, 'start_at' => '', 'end_at' => ''])->isFinal()
        );
        self::assertTrue(
            Event::fromArray(['status' => Event::STATUS_ABGESAGT, 'start_at' => '', 'end_at' => ''])->isFinal()
        );
        self::assertFalse(
            Event::fromArray(['status' => Event::STATUS_ENTWURF, 'start_at' => '', 'end_at' => ''])->isFinal()
        );
        self::assertFalse(
            Event::fromArray(['status' => Event::STATUS_VEROEFFENTLICHT, 'start_at' => '', 'end_at' => ''])->isFinal()
        );
    }

    public function test_all_statuses_constant_matches_individual_constants(): void
    {
        self::assertSame(
            [
                Event::STATUS_ENTWURF,
                Event::STATUS_VEROEFFENTLICHT,
                Event::STATUS_ABGESCHLOSSEN,
                Event::STATUS_ABGESAGT,
            ],
            Event::ALL_STATUSES
        );
    }

    public function test_default_cancel_deadline_is_positive(): void
    {
        self::assertGreaterThan(0, Event::DEFAULT_CANCEL_DEADLINE_HOURS);
    }
}
