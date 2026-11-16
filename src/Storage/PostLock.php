<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Storage;

use IndianOpinion\HeadlineOptions\Exception\BusyException;
use IndianOpinion\HeadlineOptions\Exception\StorageException;

/**
 * Case 15: one generation per post at a time.
 */
final class PostLock
{
    public const PREFIX = 'headline_options_lock_';

    public function __construct(private readonly Mutex $mutex, private readonly OptionTable $table)
    {
    }

    public function acquire(int $postId, int $ttlSeconds, int $now): void
    {
        if (!$this->mutex->acquire(self::PREFIX . $postId, $ttlSeconds, $now)) {
            if ($this->table->read(self::PREFIX . $postId) === null) {
                throw new StorageException('The generation lock cannot be written.');
            }
            throw new BusyException('Suggestions for this post are already being written. Try again in a moment.');
        }
    }

    public function release(int $postId): void
    {
        $this->mutex->release(self::PREFIX . $postId);
    }
}
