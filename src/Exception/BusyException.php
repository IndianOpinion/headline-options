<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Exception;

/**
 * Another generation for the same post is in flight.
 */
final class BusyException extends HeadlineOptionsException
{
    public function code(): string
    {
        return 'headline_options_busy';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
