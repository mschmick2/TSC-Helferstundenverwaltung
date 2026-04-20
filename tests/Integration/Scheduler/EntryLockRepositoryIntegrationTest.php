<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use App\Repositories\EntryLockRepository;
use Tests\Support\IntegrationTestCase;

/**
 * Integrationstests fuer EntryLockRepository (Modul 7 I1).
 *
 * Pruefen das reale Verhalten von UNIQUE(work_entry_id) + INSERT ... ON
 * DUPLICATE KEY UPDATE gegen echte MySQL. Benoetigt eine angelegte
 * work_entries-Zeile mit Foreign-Key-fuegbaren user_id + category_id.
 */
class EntryLockRepositoryIntegrationTest extends IntegrationTestCase
{
    private EntryLockRepository $repo;
    private int $userA;
    private int $userB;
    private int $entryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new EntryLockRepository($this->pdo());
        $this->userA = $this->createUser('lockA');
        $this->userB = $this->createUser('lockB');
        $this->entryId = $this->createWorkEntry($this->userA);
    }

    /** @test */
    public function acquire_setzt_neuen_lock_wenn_kein_eintrag_existiert(): void
    {
        $lock = $this->repo->acquireOrRefresh($this->entryId, $this->userA, null, 5);

        $this->assertNotNull($lock);
        $this->assertSame($this->userA, (int) $lock['user_id']);
        $this->assertSame($this->entryId, (int) $lock['work_entry_id']);
    }

    /** @test */
    public function zweiter_nutzer_wird_bei_aktivem_lock_abgewiesen(): void
    {
        $this->repo->acquireOrRefresh($this->entryId, $this->userA, null, 5);
        $rejected = $this->repo->acquireOrRefresh($this->entryId, $this->userB, null, 5);

        $this->assertNull($rejected);

        $active = $this->repo->findActive($this->entryId);
        $this->assertNotNull($active);
        $this->assertSame($this->userA, (int) $active['user_id']);
    }

    /** @test */
    public function eigener_refresh_verlaengert_expires_at(): void
    {
        $first = $this->repo->acquireOrRefresh($this->entryId, $this->userA, null, 5);
        $this->assertNotNull($first);
        $firstExpiry = (string) $first['expires_at'];

        // Zweiter Aufruf mit laengerem TTL muss expires_at nach vorne schieben.
        $second = $this->repo->acquireOrRefresh($this->entryId, $this->userA, null, 60);
        $this->assertNotNull($second);
        $this->assertGreaterThanOrEqual($firstExpiry, (string) $second['expires_at']);
    }

    /** @test */
    public function abgelaufener_fremder_lock_wird_uebernommen(): void
    {
        // Direkter Insert mit expires_at in der Vergangenheit (Lock abgelaufen).
        $stmt = $this->pdo()->prepare(
            'INSERT INTO entry_locks (work_entry_id, user_id, session_id, locked_at, expires_at)
             VALUES (:eid, :uid, NULL, DATE_SUB(NOW(), INTERVAL 10 MINUTE), DATE_SUB(NOW(), INTERVAL 1 MINUTE))'
        );
        $stmt->execute(['eid' => $this->entryId, 'uid' => $this->userA]);

        $taken = $this->repo->acquireOrRefresh($this->entryId, $this->userB, null, 5);

        $this->assertNotNull($taken);
        $this->assertSame($this->userB, (int) $taken['user_id']);
    }

    /** @test */
    public function release_by_user_loescht_nur_eigenen_lock(): void
    {
        $this->repo->acquireOrRefresh($this->entryId, $this->userA, null, 5);

        $removedForeign = $this->repo->releaseByUser($this->entryId, $this->userB);
        $this->assertSame(0, $removedForeign);

        $removedOwn = $this->repo->releaseByUser($this->entryId, $this->userA);
        $this->assertSame(1, $removedOwn);

        $this->assertNull($this->repo->findActive($this->entryId));
    }

    /** @test */
    public function find_active_ignoriert_abgelaufene_locks(): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO entry_locks (work_entry_id, user_id, session_id, locked_at, expires_at)
             VALUES (:eid, :uid, NULL, NOW(), DATE_SUB(NOW(), INTERVAL 1 SECOND))'
        );
        $stmt->execute(['eid' => $this->entryId, 'uid' => $this->userA]);

        $this->assertNull($this->repo->findActive($this->entryId));
    }

    /** @test */
    public function delete_stale_entfernt_nur_abgelaufene_eintraege(): void
    {
        // Aktiver Lock
        $this->repo->acquireOrRefresh($this->entryId, $this->userA, null, 60);

        // Abgelaufener Lock auf einen zweiten Entry
        $secondEntry = $this->createWorkEntry($this->userB);
        $stmt = $this->pdo()->prepare(
            'INSERT INTO entry_locks (work_entry_id, user_id, session_id, locked_at, expires_at)
             VALUES (:eid, :uid, NULL, NOW(), DATE_SUB(NOW(), INTERVAL 1 MINUTE))'
        );
        $stmt->execute(['eid' => $secondEntry, 'uid' => $this->userB]);

        $removed = $this->repo->deleteStale();

        $this->assertGreaterThanOrEqual(1, $removed);
        $this->assertNotNull($this->repo->findActive($this->entryId), 'Aktiver Lock darf nicht geloescht werden');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createUser(string $suffix): int
    {
        $stmt = $this->pdo()->prepare(
            "INSERT INTO users (mitgliedsnummer, email, password_hash, vorname, nachname, is_active, eintrittsdatum, created_at)
             VALUES (:mn, :email, :ph, :vn, :nn, 1, '2026-01-01', NOW())"
        );
        $stmt->execute([
            'mn'    => 'M-' . $suffix . '-' . bin2hex(random_bytes(3)),
            'email' => $suffix . '+' . bin2hex(random_bytes(3)) . '@test.local',
            'ph'    => password_hash('x', PASSWORD_BCRYPT),
            'vn'    => ucfirst($suffix),
            'nn'    => 'Test',
        ]);
        return (int) $this->pdo()->lastInsertId();
    }

    private function createWorkEntry(int $userId): int
    {
        $catStmt = $this->pdo()->query("SELECT id FROM categories WHERE is_active = 1 LIMIT 1");
        $catRow = $catStmt !== false ? $catStmt->fetch() : false;

        if ($catRow === false) {
            $insCat = $this->pdo()->prepare("INSERT INTO categories (name, description, is_active) VALUES ('TestKat', 'Integration', 1)");
            $insCat->execute();
            $catId = (int) $this->pdo()->lastInsertId();
        } else {
            $catId = (int) $catRow['id'];
        }

        $stmt = $this->pdo()->prepare(
            "INSERT INTO work_entries (user_id, category_id, work_date, hours, description, status, entry_number, created_at, version)
             VALUES (:uid, :cid, '2026-04-20', 1.0, 'Lock-Test', 'entwurf', :en, NOW(), 1)"
        );
        $stmt->execute([
            'uid' => $userId,
            'cid' => $catId,
            'en'  => 'LT-' . bin2hex(random_bytes(4)),
        ]);
        return (int) $this->pdo()->lastInsertId();
    }
}
