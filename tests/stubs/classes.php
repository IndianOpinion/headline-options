<?php

// phpcs:ignoreFile -- WordPress test doubles, not library code.

declare(strict_types=1);

class WP_Post
{
    public int $ID = 0;
    public string $post_title = '';
    public string $post_content = '';
    public string $post_excerpt = '';
    public string $post_type = 'post';
    public string $post_status = 'draft';
    public int $post_parent = 0;
}

class WP_Error
{
    /** @param array<string, mixed> $data */
    public function __construct(public string $code = '', public string $message = '', public array $data = [])
    {
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_data(): array
    {
        return $this->data;
    }
}

class WP_REST_Request
{
    /** @var array<string, mixed> */
    private array $params;

    /** @param array<string, mixed> $params */
    public function __construct(public string $method = 'GET', public string $route = '', array $params = [], private string $body = '')
    {
        $this->params = $params;
    }

    public function get_param(string $name): mixed
    {
        return $this->params[$name] ?? null;
    }

    public function set_param(string $name, mixed $value): void
    {
        $this->params[$name] = $value;
    }

    public function get_body(): string
    {
        return $this->body;
    }
}

class WP_REST_Response
{
    /** @var array<string, string> */
    public array $headers = [];

    public function __construct(public mixed $data = null, public int $status = 200)
    {
    }

    public function header(string $key, string $value): void
    {
        $this->headers[$key] = $value;
    }

    public function get_data(): mixed
    {
        return $this->data;
    }

    public function get_status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function get_headers(): array
    {
        return $this->headers;
    }
}

class wpdb
{
    /** @var list<string> */
    public static array $queries = [];
    public string $options = 'wp_options';

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function prepare(string $query, mixed ...$args): string
    {
        foreach ($args as $arg) {
            $query = preg_replace('/%[si]/', "'" . addslashes((string) $arg) . "'", $query, 1) ?? $query;
        }
        $query = str_replace("'wp_options'", 'wp_options', $query);

        return $query;
    }

    public function query(string $sql): int|bool
    {
        self::$queries[] = $sql;
        if (preg_match("/^DELETE FROM wp_options WHERE option_name LIKE '(.+)'$/", $sql, $m) === 1) {
            $prefix = stripslashes(str_replace(['\\_', '\\%'], ['_', '%'], rtrim($m[1], '%')));
            $prefix = rtrim($prefix, '%');
            $n = 0;
            foreach (array_keys(\IndianOpinion\HeadlineOptions\Tests\Stubs\WpState::$options[\IndianOpinion\HeadlineOptions\Tests\Stubs\WpState::$blog] ?? []) as $name) {
                if (str_starts_with((string) $name, $prefix)) {
                    unset(\IndianOpinion\HeadlineOptions\Tests\Stubs\WpState::$options[\IndianOpinion\HeadlineOptions\Tests\Stubs\WpState::$blog][$name]);
                    ++$n;
                }
            }

            return $n;
        }

        return 0;
    }

    public function get_var(string $sql): mixed
    {
        self::$queries[] = $sql;

        return null;
    }
}

class WP_CLI
{
    /** @var list<string> */
    public static array $lines = [];
    /** @var array<string, string> */
    public static array $commands = [];

    public static function reset(): void
    {
        self::$lines = [];
    }

    public static function add_command(string $name, string $class): void
    {
        self::$commands[$name] = $class;
    }

    public static function line(string $text = ''): void
    {
        self::$lines[] = $text;
    }

    public static function success(string $text): void
    {
        self::$lines[] = 'Success: ' . $text;
    }

    public static function warning(string $text): void
    {
        self::$lines[] = 'Warning: ' . $text;
    }

    /** @return never */
    public static function error(string $text): void
    {
        self::$lines[] = 'Error: ' . $text;
        throw new WpCliExit(1);
    }

    /** @return never */
    public static function halt(int $code): void
    {
        throw new WpCliExit($code);
    }

    /** @param array<string, mixed> $assoc */
    public static function confirm(string $question, array $assoc = []): void
    {
        if (empty($assoc['yes'])) {
            throw new WpCliExit(1);
        }
    }
}

final class WpCliExit extends RuntimeException
{
    public function __construct(public int $exitCode)
    {
        parent::__construct('exit ' . $exitCode);
    }
}
