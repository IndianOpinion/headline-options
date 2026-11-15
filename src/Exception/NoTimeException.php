<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Exception;

/**
 * Too little request time is left to make a model call safely.
 */
final class NoTimeException extends HeadlineOptionsException
{
    public function code(): string
    {
        return 'headline_options_no_time';
    }

    public function httpStatus(): int
    {
        return 503;
    }
}
