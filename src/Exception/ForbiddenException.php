<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Exception;

/**
 * The user may not use the plugin on this post.
 */
final class ForbiddenException extends HeadlineOptionsException
{
    public function code(): string
    {
        return 'headline_options_forbidden';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
