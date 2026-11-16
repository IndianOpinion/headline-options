<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Storage;

use IndianOpinion\HeadlineOptions\Clock;
use IndianOpinion\HeadlineOptions\Exception\CapReachedException;
use IndianOpinion\HeadlineOptions\Exception\StorageException;

/**
 * Day counters (site and per user) and the per-user rate limit, in one
 * option row, changed only while a mutex row is held. Two requests at cap
 * minus one cannot both pass: the second waits for the first, then reads the
 * new count.
 *
 * The day is the site-local day. Previous days are dropped on the first
 * write of a new day.
 */
final class UsageStore
{
    public const OPTION = 'headline_options_usage';
    public const LOCK = 'headline_options_usage_lock';

    public function __construct(
        private readonly OptionTable $table,
        private readonly Mutex $mutex,
        private readonly Clock $clock,
        private readonly int $lockWaitMs = 2000,
    ) {
    }

    /**
     * Count one generation. Throws when a cap or the rate limit refuses it.
     *
     * @param int|null $userId Null for WP-CLI: only the site cap applies.
     * @return array{day: string, site: int, user: int}
     *
     * @throws CapReachedException
     * @throws StorageException
     */
    public function reserve(?int $userId, int $siteCap, int $userCap, int $ratePerMinute): array
    {
        return $this->locked(function (array $record) use ($userId, $siteCap, $userCap, $ratePerMinute): array {
            $now = $this->clock->now()->getTimestamp();
            $uid = $userId === null ? 'cli' : (string) $userId;
            $site = (int) $record['site'];
            $user = (int) ($record['users'][$uid] ?? 0);
            $reset = $this->resetTime();
            if ($site >= $siteCap) {
                $first = empty($record['notified']['site']);
                $record['notified']['site'] = true;
                $this->save($record);
                throw new CapReachedException(
                    'The site has used today\'s suggestions. The cap resets at ' . $reset . '.',
                    CapReachedException::SITE,
                    $site,
                    $siteCap,
                    null,
                    ['resets_at' => $reset, 'first_refusal' => $first],
                );
            }
            if ($userId !== null) {
                if ($user >= $userCap) {
                    $first = empty($record['notified']['user'][$uid]);
                    $record['notified']['user'][$uid] = true;
                    $this->save($record);
                    throw new CapReachedException(
                        'You have used today\'s suggestions. The cap resets at ' . $reset . '.',
                        CapReachedException::USER,
                        $user,
                        $userCap,
                        null,
                        ['resets_at' => $reset, 'first_refusal' => $first],
                    );
                }
                $recent = array_values(array_filter(
                    $record['rate'][$uid] ?? [],
                    static fn ($t): bool => is_int($t) && $t > $now - 60,
                ));
                if ($recent !== [] && count($recent) >= $ratePerMinute) {
                    $retry = max(1, (int) min($recent) + 60 - $now);
                    throw new CapReachedException(
                        sprintf('Too many requests. Try again in %d seconds.', $retry),
                        CapReachedException::RATE,
                        count($recent),
                        $ratePerMinute,
                        $retry,
                        ['retry_after' => $retry],
                    );
                }
                $recent[] = $now;
                $record['rate'][$uid] = $recent;
            }
            $record['site'] = $site + 1;
            $record['users'][$uid] = $user + 1;
            $this->save($record);

            return ['day' => (string) $record['day'], 'site' => $site + 1, 'user' => $user + 1];
        });
    }

    /**
     * Give back one count (the call was never attempted).
     */
    public function release(?int $userId, string $day): void
    {
        $this->locked(function (array $record) use ($userId, $day): array {
            if ($record['day'] !== $day) {
                return [];
            }
            $uid = $userId === null ? 'cli' : (string) $userId;
            $record['site'] = max(0, (int) $record['site'] - 1);
            $record['users'][$uid] = max(0, (int) ($record['users'][$uid] ?? 0) - 1);
            if (!empty($record['rate'][$uid]) && is_array($record['rate'][$uid])) {
                array_pop($record['rate'][$uid]);
            }
            $this->save($record);

            return [];
        });
    }

    /**
     * Today's counts, read without the lock.
     *
     * @return array{day: string, site: int, user: int}
     */
    public function snapshot(?int $userId): array
    {
        $record = $this->load();
        $uid = $userId === null ? 'cli' : (string) $userId;

        return ['day' => (string) $record['day'], 'site' => (int) $record['site'], 'user' => (int) ($record['users'][$uid] ?? 0)];
    }

    /** Zero today's counters. */
    public function reset(): void
    {
        $this->locked(function (array $record): array {
            $this->save(self::empty($this->today()));

            return [];
        });
    }

    /** The local time the day counters reset, ISO 8601 with offset. */
    public function resetTime(): string
    {
        return $this->clock->now()->setTime(0, 0)->modify('+1 day')->format(DATE_ATOM);
    }

    /**
     * @template T of array
     * @param callable(array<string, mixed>): T $fn
     * @return T
     */
    private function locked(callable $fn): array
    {
        $now = $this->clock->now()->getTimestamp();
        if (!$this->mutex->acquire(self::LOCK, 10, $now, $this->lockWaitMs)) {
            throw new StorageException('The usage counter is busy or cannot be written, so the cap cannot be checked.');
        }
        try {
            return $fn($this->load());
        } finally {
            $this->mutex->release(self::LOCK);
        }
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        $today = $this->today();
        $raw = $this->table->read(self::OPTION);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record) || !isset($record['day'])) {
            return self::empty($today);
        }
        if ($record['day'] !== $today) {
            $fresh = self::empty($today);
            $fresh['rate'] = is_array($record['rate'] ?? null) ? $record['rate'] : [];

            return $fresh;
        }
        $record['site'] = (int) ($record['site'] ?? 0);
        $record['users'] = is_array($record['users'] ?? null) ? $record['users'] : [];
        $record['rate'] = is_array($record['rate'] ?? null) ? $record['rate'] : [];
        $record['notified'] = is_array($record['notified'] ?? null) ? $record['notified'] : [];

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function save(array $record): void
    {
        $json = json_encode($record);
        if ($json === false || !$this->table->write(self::OPTION, $json)) {
            throw new StorageException('The usage counter could not be written, so the cap cannot be proven.');
        }
    }

    private function today(): string
    {
        return $this->clock->now()->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    private static function empty(string $day): array
    {
        return ['day' => $day, 'site' => 0, 'users' => [], 'rate' => [], 'notified' => []];
    }
}
