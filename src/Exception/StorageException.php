<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Exception;

/**
 * A counter or lock could not be read or written, so the cap cannot be proven.
 */
final class StorageException extends HeadlineOptionsException
{
    public function code(): string
    {
        return 'headline_options_storage';
    }

    public function httpStatus(): int
    {
        return 503;
    }
}
