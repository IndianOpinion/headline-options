<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Storage;

/**
 * A lock held in one option row. The value is the expiry time followed by a
 * random token, so a lock left by a crashed request is taken over once it
 * expires, and a holder whose lock expired and was taken over cannot delete
 * the new holder's row on release.
 */
final class Mutex
{
    /** @var callable(int): void */
    private $sleep;

    /** @var array<string, string> lock name => the row value this instance wrote */
    private array $held = [];

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
        $value = ($now + $ttlSeconds) . ':' . bin2hex(random_bytes(8));
        for ($i = 0; $i < $tries; ++$i) {
            if ($this->table->insertIfAbsent($name, $value)) {
                $this->held[$name] = $value;

                return true;
            }
            $stored = $this->table->read($name);
            if ($stored !== null && (int) $stored < $now) {
                // Expired: remove and try again at once.
                $this->table->deleteIfValue($name, $stored);
                if ($this->table->insertIfAbsent($name, $value)) {
                    $this->held[$name] = $value;

                    return true;
                }
            }
            if ($i + 1 < $tries) {
                ($this->sleep)(25000);
            }
        }

        return false;
    }

    /**
     * Release a lock this instance holds. Only the row this instance wrote is
     * deleted: if the lock expired and another request took it over, that
     * request's row stays.
     */
    public function release(string $name): void
    {
        if (!isset($this->held[$name])) {
            return;
        }
        $this->table->deleteIfValue($name, $this->held[$name]);
        unset($this->held[$name]);
    }
}
