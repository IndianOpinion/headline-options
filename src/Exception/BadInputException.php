<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Exception;

/**
 * The request carried invalid UTF-8, a text over its size limit, or a value out of range.
 */
final class BadInputException extends HeadlineOptionsException
{
    public function code(): string
    {
        return 'headline_options_bad_input';
    }

    public function httpStatus(): int
    {
        return 400;
    }
}
