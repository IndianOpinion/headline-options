<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions;

/**
 * Plugin settings: defaults, ranges and sanitising. Pure PHP; the WordPress
 * option is read and written by the glue in `WordPress\Options`.
 */
final class Settings
{
    public const PROVIDERS = ['openrouter', 'openai', 'compatible'];

    /** @var array<string, array{0: int, 1: int}> */
    public const RANGES = [
        'count' => [3, 10],
        'site_cap' => [0, 10000],
        'user_cap' => [0, 1000],
        'rate_per_minute' => [1, 30],
        'input_limit' => [1000, 30000],
        'min_words' => [10, 500],
    ];

    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param array<string, mixed> $values Raw stored values; anything missing or invalid takes the default.
     */
    public function __construct(array $values = [])
    {
        $this->values = self::sanitize($values);
    }

    /**
     * @return array{
     *     enabled: bool,
     *     provider: string,
     *     base_url: string,
     *     models: list<string>,
     *     count: int,
     *     site_cap: int,
     *     user_cap: int,
     *     rate_per_minute: int,
     *     input_limit: int,
     *     min_words: int,
     *     post_types: list<string>,
     *     style_note: string,
     *     confirm_use: bool,
     *     length_pass: array{0: int, 1: int},
     *     length_warn: array{0: int, 1: int}
     * }
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'provider' => 'openrouter',
            'base_url' => '',
            'models' => [],
            'count' => 5,
            'site_cap' => 50,
            'user_cap' => 15,
            'rate_per_minute' => 4,
            'input_limit' => 6000,
            'min_words' => 40,
            'post_types' => ['post'],
            'style_note' => '',
            'confirm_use' => true,
            'length_pass' => [30, 70],
            'length_warn' => [20, 90],
        ];
    }

    /**
     * Clean stored or submitted values. Empty or non-numeric numbers fall back
     * to the default (there is no "unlimited"); numbers out of range are
     * clamped.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $input): array
    {
        $d = self::defaults();
        $out = $d;
        $out['enabled'] = self::toBool($input['enabled'] ?? $d['enabled']);
        $provider = is_string($input['provider'] ?? null) ? $input['provider'] : $d['provider'];
        $out['provider'] = in_array($provider, self::PROVIDERS, true) ? $provider : $d['provider'];
        $url = is_string($input['base_url'] ?? null) ? trim($input['base_url']) : '';
        $out['base_url'] = ($url !== '' && preg_match('#^https?://[^\s]+$#i', $url) === 1) ? $url : '';
        $models = $input['models'] ?? [];
        if (is_string($models)) {
            $models = preg_split('/[\r\n,]+/', $models) ?: [];
        }
        $clean = [];
        foreach (is_array($models) ? $models : [] as $model) {
            if (is_string($model)) {
                $model = trim($model);
                if ($model !== '' && preg_match('#^[A-Za-z0-9._:/@+\-]{1,200}$#', $model) === 1 && !in_array($model, $clean, true)) {
                    $clean[] = $model;
                }
            }
        }
        $out['models'] = array_slice($clean, 0, 3);
        foreach (self::RANGES as $key => [$min, $max]) {
            $out[$key] = self::toInt($input[$key] ?? null, $d[$key], $min, $max);
        }
        $types = $input['post_types'] ?? $d['post_types'];
        if (is_string($types)) {
            $types = preg_split('/[\s,]+/', $types) ?: [];
        }
        $cleanTypes = [];
        foreach (is_array($types) ? $types : [] as $type) {
            if (is_string($type) && preg_match('/^[a-z0-9_\-]{1,20}$/', $type) === 1 && !in_array($type, $cleanTypes, true)) {
                $cleanTypes[] = $type;
            }
        }
        $out['post_types'] = $cleanTypes;
        $note = is_string($input['style_note'] ?? null) ? $input['style_note'] : '';
        $note = trim(strip_tags($note));
        $out['style_note'] = mb_substr($note, 0, 500, 'UTF-8');
        $out['confirm_use'] = self::toBool($input['confirm_use'] ?? $d['confirm_use']);

        $pass = self::pair($input['length_pass'] ?? null, $d['length_pass']);
        $warn = self::pair($input['length_warn'] ?? null, $d['length_warn']);
        // The warn range must be at least as wide as the pass range.
        $warn = [min($warn[0], $pass[0]), max($warn[1], $pass[1])];
        $out['length_pass'] = $pass;
        $out['length_warn'] = $warn;

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public function int(string $key): int
    {
        $v = $this->values[$key] ?? 0;

        return is_int($v) ? $v : 0;
    }

    public function bool(string $key): bool
    {
        return ($this->values[$key] ?? false) === true;
    }

    public function string(string $key): string
    {
        $v = $this->values[$key] ?? '';

        return is_string($v) ? $v : '';
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $v = $this->values[$key] ?? [];
        if (!is_array($v)) {
            return [];
        }

        return array_values(array_filter($v, 'is_string'));
    }

    /** @return array{0: int, 1: int} */
    public function range(string $key): array
    {
        $v = $this->values[$key] ?? [0, 0];

        return is_array($v) && isset($v[0], $v[1]) ? [(int) $v[0], (int) $v[1]] : [0, 0];
    }

    private static function toBool(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'on' || $v === 'yes' || $v === 'true';
    }

    private static function toInt(mixed $v, int $default, int $min, int $max): int
    {
        if (is_string($v)) {
            $v = trim($v);
            if ($v === '' || preg_match('/^-?\d+$/', $v) !== 1) {
                return $default;
            }
            $v = (int) $v;
        }
        if (!is_int($v)) {
            return $default;
        }

        return max($min, min($max, $v));
    }

    /**
     * @param array{0: int, 1: int} $default
     * @return array{0: int, 1: int}
     */
    private static function pair(mixed $v, array $default): array
    {
        if (!is_array($v) || !isset($v[0], $v[1])) {
            return $default;
        }
        $a = self::toInt(is_int($v[0]) ? $v[0] : (string) (is_scalar($v[0]) ? $v[0] : ''), -1, 10, 200);
        $b = self::toInt(is_int($v[1]) ? $v[1] : (string) (is_scalar($v[1]) ? $v[1] : ''), -1, 10, 200);
        if ($a < 0 || $b < 0 || $a > $b) {
            return $default;
        }

        return [$a, $b];
    }
}
