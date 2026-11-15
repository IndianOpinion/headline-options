<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions;

/**
 * Calls WordPress hooks when WordPress is loaded, and is a no-op otherwise,
 * so the scoring and cleaning classes run outside WordPress.
 */
final class Hooks
{
    /**
     * @param non-empty-string $name
     */
    public static function filter(string $name, mixed $value, mixed ...$args): mixed
    {
        if (!function_exists('apply_filters')) {
            return $value;
        }

        return apply_filters($name, $value, ...$args);
    }

    /**
     * @param non-empty-string $name
     */
    public static function action(string $name, mixed ...$args): void
    {
        if (function_exists('do_action')) {
            do_action($name, ...$args);
        }
    }
}
