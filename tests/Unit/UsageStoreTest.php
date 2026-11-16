<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Tests\Unit;

use IndianOpinion\HeadlineOptions\Exception\CapReachedException;
use IndianOpinion\HeadlineOptions\Exception\StorageException;
use IndianOpinion\HeadlineOptions\Storage\MemoryOptionTable;
use IndianOpinion\HeadlineOptions\Storage\Mutex;
use IndianOpinion\HeadlineOptions\Storage\UsageStore;
use IndianOpinion\HeadlineOptions\Tests\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * T48 to T51, T59 at the storage layer.
 */
final class UsageStoreTest extends TestCase
{
    private MemoryOptionTable $table;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->table = new MemoryOptionTable();
        $this->clock = new FixedClock('2026-11-15 23:59:30');
    }

    private function store(int $waitMs = 0): UsageStore
    {
        return new UsageStore($this->table, new Mutex($this->table, static function (int $us): void {
        }), $this->clock, $waitMs);
    }

    public function testCountsAndSiteCapWithResetTime(): void
    {
        $s = $this->store();
        $s->reserve(1, 2, 10, 30);
        $s->reserve(2, 2, 10, 30);
        try {
            $s->reserve(3, 2, 10, 30);
            self::fail('cap not enforced');
        } catch (CapReachedException $e) {
            self::assertSame('headline_options_daily_cap', $e->code());
            self::assertSame(429, $e->httpStatus());
            self::assertSame('2026-11-16T00:00:00+05:30', $e->data()['resets_at']);
            self::assertTrue($e->data()['first_refusal']);
        }
        try {
            $s->reserve(3, 2, 10, 30);
        } catch (CapReachedException $e) {
            self::assertFalse($e->data()['first_refusal']);
        }
    }

    public function testNewDayResetsCounters(): void
    {
        $s = $this->store();
        $s->reserve(1, 1, 10, 30);
        $this->clock->advance(60);
        $snap = $s->reserve(1, 1, 10, 30);
        self::assertSame('2026-11-16', $snap['day']);
        self::assertSame(1, $snap['site']);
    }

    public function testUserCapAndRateLimit(): void
    {
        $s = $this->store();
        $s->reserve(1, 50, 2, 30);
        $s->reserve(1, 50, 2, 30);
        try {
            $s->reserve(1, 50, 2, 30);
            self::fail('user cap');
        } catch (CapReachedException $e) {
            self::assertSame('headline_options_user_cap', $e->code());
        }
        $s->reserve(2, 50, 10, 2);
        $s->reserve(2, 50, 10, 2);
        try {
            $s->reserve(2, 50, 10, 2);
            self::fail('rate');
        } catch (CapReachedException $e) {
            self::assertSame('headline_options_rate_limited', $e->code());
            self::assertSame(60, $e->retryAfter());
        }
        $this->clock->advance(61);
        $s->reserve(2, 500, 10, 2);
        // The minute passed midnight, so the day counter started again.
        self::assertSame(1, $s->snapshot(2)['user']);
    }

    public function testT50TwoRequestsAtCapMinusOneOnlyOnePasses(): void
    {
        $s = $this->store();
        $s->reserve(1, 3, 10, 30);
        $s->reserve(1, 3, 10, 30);
        // Second process holds the mutex while it writes: the other must not pass on a stale read.
        self::assertTrue((new Mutex($this->table))->acquire(UsageStore::LOCK, 10, $this->clock->now()->getTimestamp()));
        try {
            $s->reserve(2, 3, 10, 30);
            self::fail('passed while the counter was locked');
        } catch (StorageException $e) {
            self::assertSame('headline_options_storage', $e->code());
        }
        $this->table->delete(UsageStore::LOCK);
        $passed = 0;
        foreach ([2, 3] as $uid) {
            try {
                $s->reserve($uid, 3, 10, 30);
                ++$passed;
            } catch (CapReachedException) {
            }
        }
        self::assertSame(1, $passed);
    }

    public function testExpiredLockIsTakenOver(): void
    {
        $this->table->rows[UsageStore::LOCK] = (string) ($this->clock->now()->getTimestamp() - 5);
        $this->store()->reserve(1, 3, 10, 30);
        self::assertArrayNotHasKey(UsageStore::LOCK, $this->table->rows);
    }

    public function testT59WriteFailureRefuses(): void
    {
        $this->table->failWrites = true;
        $this->expectException(StorageException::class);
        $this->store()->reserve(1, 3, 10, 30);
    }

    public function testReleaseGivesBack(): void
    {
        $s = $this->store();
        $r = $s->reserve(1, 3, 10, 30);
        $s->release(1, $r['day']);
        self::assertSame(0, $s->snapshot(1)['site']);
        self::assertSame(0, $s->snapshot(1)['user']);
    }

    public function testCliHasNoUserCap(): void
    {
        $s = $this->store();
        for ($i = 0; $i < 5; ++$i) {
            $s->reserve(null, 10, 1, 1);
        }
        self::assertSame(5, $s->snapshot(null)['site']);
    }

    public function testReleaseAfterTakeoverLeavesTheNewHoldersLock(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $first = new Mutex($this->table);
        self::assertTrue($first->acquire('headline_options_lock_5', 10, $now));
        // The first holder stalls past its expiry; a second request takes the lock over.
        $second = new Mutex($this->table);
        self::assertTrue($second->acquire('headline_options_lock_5', 10, $now + 11));
        $first->release('headline_options_lock_5');
        self::assertNotNull($this->table->read('headline_options_lock_5'));
        self::assertFalse((new Mutex($this->table))->acquire('headline_options_lock_5', 10, $now + 12));
        $second->release('headline_options_lock_5');
        self::assertNull($this->table->read('headline_options_lock_5'));
    }
}
