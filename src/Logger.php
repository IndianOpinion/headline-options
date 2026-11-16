<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions;

/**
 * Log lines go to the `headline_options_log` action and, when
 * `WP_DEBUG_LOG` is on, to `error_log` with a `[headline-options]` prefix.
 *
 * Context carries ids, codes, counts and durations only: never article
 * text, headline text or a key. Values that are not scalars are dropped.
 */
final class Logger
{
    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $clean[$key] = $value;
            } elseif (is_string($value) && mb_strlen($value, 'UTF-8') <= 80) {
                $clean[$key] = $value;
            }
        }
        Hooks::action('headline_options_log', $level, $message, $clean);
        if (defined('WP_DEBUG_LOG') && constant('WP_DEBUG_LOG')) {
            $line = '[headline-options] ' . $level . ': ' . $message;
            if ($clean !== []) {
                $line .= ' ' . (string) json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            error_log($line); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }
}
