<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Exception;

/**
 * The article text is under the minimum word count. Nothing was spent or counted.
 */
final class TooShortException extends HeadlineOptionsException
{
    public function code(): string
    {
        return 'headline_options_too_short';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
