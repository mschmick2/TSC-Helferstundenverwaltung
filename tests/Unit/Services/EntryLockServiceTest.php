<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\User;
use App\Repositories\EntryLockRepository;
use App\Repositories\UserRepository;
use App\Services\EntryLockService;
use App\Services\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EntryLockServiceTest extends TestCase
{
    private EntryLockRepository&MockObject $lockRepo;
    private UserRepository&MockObject $userRepo;
    private SettingsService&MockObject $settings;
    private EntryLockService $service;

    protected function setUp(): void
    {
        $this->lockRepo = $this->createMock(EntryLockRepository::class);
        $this->userRepo = $this->createMock(UserRepository::class);
        $this->settings = $this->createMock(SettingsService::class);
        $this->service = new EntryLockService(
            $this->lockRepo,
            $this->userRepo,
            $this->settings
        );
    }

    // -------------------------------------------------------------------------
    // TTL-Handling
    // -------------------------------------------------------------------------

    /** @test */
    public function ttl_kommt_aus_setting_lock_timeout_minutes(): void
    {
        $this->settings->method('getInt')
            ->with('lock_timeout_minutes', 5)
            ->willReturn(10);

        $this->assertSame(10, $this->service->getTtlMinutes());
    }

    /** @test */
    public function ttl_faellt_bei_0_oder_negativ_auf_5_zurueck(): void
    {
        $this->settings->method('getInt')->willReturn(0);
        $this->assertSame(5, $this->service->getTtlMinutes());
    }

    // -------------------------------------------------------------------------
    // tryAcquire
    // -------------------------------------------------------------------------

    /** @test */
    public function erfolgreiches_acquire_liefert_lock_daten(): void
    {
        $this->settings->method('getInt')->willReturn(5);
        $this->lockRepo->method('acquireOrRefresh')
            ->with(42, 7, 99, 5)
            ->willReturn([
                'id'         => 123,
                'expires_at' => '2026-04-20 12:34:56',
                'user_id'    => 7,
            ]);

        $result = $this->service->tryAcquire(42, 7, 99);

        $this->assertTrue($result['success']);
        $this->assertSame(123, $result['lock']['id']);
        $this->assertSame('2026-04-20 12:34:56', $result['lock']['expires_at']);
    }

    /** @test */
    public function konflikt_liefert_haltender_nutzer_mit_name(): void
    {
        $this->settings->method('getInt')->willReturn(5);
        $this->lockRepo->method('acquireOrRefresh')->willReturn(null);
        $this->lockRepo->method('findActive')->with(42)->willReturn([
            'id'         => 10,
            'user_id'    => 99,
            'expires_at' => '2026-04-20 12:40:00',
        ]);

        $holder = $this->makeUser(99, 'Max', 'Mustermann');
        $this->userRepo->method('findById')->with(99)->willReturn($holder);

        $result = $this->service->tryAcquire(42, 7, null);

        $this->assertFalse($result['success']);
        $this->assertSame(99, $result['held_by']['user_id']);
        $this->assertSame('Max Mustermann', $result['held_by']['name']);
        $this->assertSame('2026-04-20 12:40:00', $result['held_by']['expires_at']);
    }

    /** @test */
    public function konflikt_ohne_auffindbaren_halter_liefert_unbekannt(): void
    {
        $this->settings->method('getInt')->willReturn(5);
        $this->lockRepo->method('acquireOrRefresh')->willReturn(null);
        $this->lockRepo->method('findActive')->willReturn([
            'id'         => 10,
            'user_id'    => 99,
            'expires_at' => '2026-04-20 12:40:00',
        ]);
        $this->userRepo->method('findById')->willReturn(null);

        $result = $this->service->tryAcquire(42, 7, null);

        $this->assertFalse($result['success']);
        $this->assertSame('Unbekannt', $result['held_by']['name']);
    }

    /** @test */
    public function race_zwischen_acquire_und_find_active_liefert_unbekannt(): void
    {
        $this->settings->method('getInt')->willReturn(5);
        $this->lockRepo->method('acquireOrRefresh')->willReturn(null);
        $this->lockRepo->method('findActive')->willReturn(null);

        $result = $this->service->tryAcquire(42, 7, null);

        $this->assertFalse($result['success']);
        $this->assertSame('Unbekannt', $result['held_by']['name']);
    }

    // -------------------------------------------------------------------------
    // release
    // -------------------------------------------------------------------------

    /** @test */
    public function release_gibt_true_wenn_zeile_entfernt_wurde(): void
    {
        $this->lockRepo->method('releaseByUser')->with(42, 7)->willReturn(1);
        $this->assertTrue($this->service->release(42, 7));
    }

    /** @test */
    public function release_gibt_false_wenn_nichts_zu_entfernen_war(): void
    {
        $this->lockRepo->method('releaseByUser')->willReturn(0);
        $this->assertFalse($this->service->release(42, 7));
    }

    // -------------------------------------------------------------------------
    // checkStatus
    // -------------------------------------------------------------------------

    /** @test */
    public function check_status_ohne_aktiven_lock_ist_frei(): void
    {
        $this->lockRepo->method('findActive')->willReturn(null);
        $this->assertSame(['held_by_other' => false], $this->service->checkStatus(42, 7));
    }

    /** @test */
    public function check_status_eigener_lock_meldet_frei(): void
    {
        $this->lockRepo->method('findActive')->willReturn([
            'id'         => 1,
            'user_id'    => 7,
            'expires_at' => '2026-04-20 13:00:00',
        ]);
        $this->assertSame(['held_by_other' => false], $this->service->checkStatus(42, 7));
    }

    /** @test */
    public function check_status_fremder_lock_meldet_halter(): void
    {
        $this->lockRepo->method('findActive')->willReturn([
            'id'         => 1,
            'user_id'    => 99,
            'expires_at' => '2026-04-20 13:00:00',
        ]);
        $this->userRepo->method('findById')->with(99)->willReturn(
            $this->makeUser(99, 'Eva', 'Beispiel')
        );

        $result = $this->service->checkStatus(42, 7);

        $this->assertTrue($result['held_by_other']);
        $this->assertSame('Eva Beispiel', $result['name']);
        $this->assertSame('2026-04-20 13:00:00', $result['expires_at']);
    }

    // -------------------------------------------------------------------------
    // cleanupStale
    // -------------------------------------------------------------------------

    /** @test */
    public function cleanup_stale_reicht_repository_zurueck(): void
    {
        $this->lockRepo->method('deleteStale')->willReturn(3);
        $this->assertSame(3, $this->service->cleanupStale());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeUser(int $id, string $vorname, string $nachname): User
    {
        return User::fromArray([
            'id'              => $id,
            'vorname'         => $vorname,
            'nachname'        => $nachname,
            'email'           => 'x@y.z',
            'mitgliedsnummer' => 'M' . $id,
            'password_hash'   => '$2y$12$' . str_repeat('a', 53),
            'is_active'       => 1,
            'created_at'      => '2026-01-01 00:00:00',
        ]);
    }
}
