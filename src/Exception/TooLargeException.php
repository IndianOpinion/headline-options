<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Exception;

/**
 * The request body is over the size limit.
 */
final class TooLargeException extends HeadlineOptionsException
{
    public function code(): string
    {
        return 'headline_options_too_large';
    }

    public function httpStatus(): int
    {
        return 413;
    }
}
