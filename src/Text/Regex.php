<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Text;

/**
 * Checks a regular expression supplied through a filter before it is used.
 */
final class Regex
{
    public static function isValid(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            $ok = preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }

        return $ok;
    }
}
