<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Exception;

/**
 * The model answered, but no candidate survived cleaning.
 */
final class NoCandidatesException extends HeadlineOptionsException
{
    public function code(): string
    {
        return 'headline_options_no_candidates';
    }

    public function httpStatus(): int
    {
        return 502;
    }
}
