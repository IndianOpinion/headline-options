<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Exception;

/**
 * The post does not exist or is in the trash.
 */
final class NotFoundException extends HeadlineOptionsException
{
    public function code(): string
    {
        return 'headline_options_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
