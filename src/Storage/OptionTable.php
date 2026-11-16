<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Storage;

/**
 * Raw, uncached access to single option rows. `insertIfAbsent` is atomic:
 * of two concurrent callers exactly one gets true.
 */
interface OptionTable
{
    /**
     * Insert the row only when no row with that name exists.
     *
     * @phpstan-impure
     */
    public function insertIfAbsent(string $name, string $value): bool;

    /**
     * The stored value, read past any cache, or null.
     */
    public function read(string $name): ?string;

    /**
     * Insert or replace. False when the write failed.
     *
     * @phpstan-impure
     */
    public function write(string $name, string $value): bool;

    public function delete(string $name): bool;

    /**
     * Delete only when the stored value still equals $value.
     *
     * @phpstan-impure
     */
    public function deleteIfValue(string $name, string $value): bool;
}
