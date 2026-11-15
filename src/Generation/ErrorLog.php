<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Generation;

/**
 * Remembers the last error code and time, and the last success time, for the
 * status screen and the credit notice. Never stores text.
 */
final class ErrorLog
{
    public const OPTION = 'headline_options_last_error';

    public function failure(string $code, int $time): void
    {
        $state = $this->state();
        $state['code'] = $code;
        $state['time'] = $time;
        $this->save($state);
    }

    public function success(int $time): void
    {
        $state = $this->state();
        $state['last_success'] = $time;
        $this->save($state);
    }

    /**
     * @return array{code: ?string, time: ?int, last_success: ?int}
     */
    public function state(): array
    {
        $raw = function_exists('get_option') ? get_option(self::OPTION, []) : [];
        $raw = is_array($raw) ? $raw : [];

        return [
            'code' => is_string($raw['code'] ?? null) ? $raw['code'] : null,
            'time' => is_int($raw['time'] ?? null) ? $raw['time'] : null,
            'last_success' => is_int($raw['last_success'] ?? null) ? $raw['last_success'] : null,
        ];
    }

    /** Case 59: credit ran out and no success since. */
    public function creditNotice(): bool
    {
        $s = $this->state();

        return $s['code'] === 'headline_options_provider_credit' && ($s['last_success'] === null || ($s['time'] ?? 0) >= $s['last_success']);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save(array $state): void
    {
        if (function_exists('update_option')) {
            update_option(self::OPTION, $state, false);
        }
    }
}
