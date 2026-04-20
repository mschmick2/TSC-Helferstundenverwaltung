<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\EventTask;
use PHPUnit\Framework\TestCase;

final class EventTaskTest extends TestCase
{
    public function test_isContribution_true_for_beigabe_type(): void
    {
        $t = EventTask::fromArray(['title' => 'Kuchen', 'task_type' => EventTask::TYPE_BEIGABE]);
        self::assertTrue($t->isContribution());

        $a = EventTask::fromArray(['title' => 'Getraenke', 'task_type' => EventTask::TYPE_AUFGABE]);
        self::assertFalse($a->isContribution());
    }

    public function test_hasFixedSlot_true_for_slot_fix(): void
    {
        $fix = EventTask::fromArray(['slot_mode' => EventTask::SLOT_FIX]);
        self::assertTrue($fix->hasFixedSlot());

        $var = EventTask::fromArray(['slot_mode' => EventTask::SLOT_VARIABEL]);
        self::assertFalse($var->hasFixedSlot());
    }

    public function test_fromArray_uses_sensible_defaults(): void
    {
        $t = EventTask::fromArray(['title' => 'Minimal']);
        self::assertSame(EventTask::TYPE_AUFGABE, $t->getTaskType());
        self::assertSame(EventTask::SLOT_FIX, $t->getSlotMode());
        self::assertSame(EventTask::CAP_UNBEGRENZT, $t->getCapacityMode());
        self::assertNull($t->getCapacityTarget());
        self::assertSame(0.0, $t->getHoursDefault());
        // Modul 7 I3: Default-Version darf nicht knallen, wenn Zeile aelter als
        // Migration 007 ist.
        self::assertSame(1, $t->getVersion());
    }

    public function test_fromArray_parses_version(): void
    {
        $t = EventTask::fromArray(['title' => 'x', 'version' => 5]);
        self::assertSame(5, $t->getVersion());
    }

    public function test_constants_match_database_enum_values(): void
    {
        // Diese Werte MUESSEN mit den ENUM-Werten im Schema
        // (scripts/database/migrations/002_module_events.sql) uebereinstimmen.
        // Wenn Schema-ENUM geaendert wird, hier mit-anpassen!
        self::assertSame('aufgabe', EventTask::TYPE_AUFGABE);
        self::assertSame('beigabe', EventTask::TYPE_BEIGABE);
        self::assertSame('fix', EventTask::SLOT_FIX);
        self::assertSame('variabel', EventTask::SLOT_VARIABEL);
        self::assertSame('unbegrenzt', EventTask::CAP_UNBEGRENZT);
        self::assertSame('ziel', EventTask::CAP_ZIEL);
        self::assertSame('maximum', EventTask::CAP_MAXIMUM);
    }

    public function test_hours_default_preserves_precision(): void
    {
        $t = EventTask::fromArray(['hours_default' => 2.75]);
        self::assertSame(2.75, $t->getHoursDefault());
    }
}
