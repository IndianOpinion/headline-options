<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Storage;

/**
 * A lock held in one option row. The value is the expiry time, so a lock
 * left by a crashed request is taken over once it expires.
 */
final class Mutex
{
    /** @var callable(int): void */
    private $sleep;

    /**
     * @param (callable(int): void)|null $sleep sleeps the given microseconds
     */
    public function __construct(private readonly OptionTable $table, ?callable $sleep = null)
    {
        $this->sleep = $sleep ?? static function (int $us): void {
            usleep($us);
        };
    }

    /**
     * Try to take the lock, waiting up to $waitMs. True when taken.
     */
    public function acquire(string $name, int $ttlSeconds, int $now, int $waitMs = 0): bool
    {
        $tries = max(1, intdiv($waitMs, 25) + 1);
        for ($i = 0; $i < $tries; ++$i) {
            if ($this->table->insertIfAbsent($name, (string) ($now + $ttlSeconds))) {
                return true;
            }
            $held = $this->table->read($name);
            if ($held !== null && (int) $held < $now) {
                // Expired: remove and try again at once.
                $this->table->deleteIfValue($name, $held);
                if ($this->table->insertIfAbsent($name, (string) ($now + $ttlSeconds))) {
                    return true;
                }
            }
            if ($i + 1 < $tries) {
                ($this->sleep)(25000);
            }
        }

        return false;
    }

    public function release(string $name): void
    {
        $this->table->delete($name);
    }
}
