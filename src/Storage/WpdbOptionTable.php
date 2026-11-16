<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Storage;

/**
 * Option rows through `$wpdb`. `INSERT IGNORE` on the unique `option_name`
 * key is the atomic step (the same technique WordPress core uses for its
 * upgrade lock). Rows are written with autoload off and the options cache
 * entries are cleared after every write.
 */
final class WpdbOptionTable implements OptionTable
{
    public function __construct(private readonly \wpdb $db)
    {
    }

    public function insertIfAbsent(string $name, string $value): bool
    {
        $sql = $this->db->prepare(
            'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, \'off\')',
            $this->db->options,
            $name,
            $value
        );
        $rows = is_string($sql) ? $this->db->query($sql) : false;
        $this->forget($name);

        return $rows === 1;
    }

    public function read(string $name): ?string
    {
        $sql = $this->db->prepare('SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $this->db->options, $name);
        if (!is_string($sql)) {
            return null;
        }
        $value = $this->db->get_var($sql);

        return is_string($value) ? $value : null;
    }

    public function write(string $name, string $value): bool
    {
        $sql = $this->db->prepare(
            'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, \'off\') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
            $this->db->options,
            $name,
            $value
        );
        $rows = is_string($sql) ? $this->db->query($sql) : false;
        $this->forget($name);

        return $rows !== false;
    }

    public function delete(string $name): bool
    {
        $sql = $this->db->prepare('DELETE FROM %i WHERE option_name = %s', $this->db->options, $name);
        $rows = is_string($sql) ? $this->db->query($sql) : false;
        $this->forget($name);

        return $rows !== false;
    }

    public function deleteIfValue(string $name, string $value): bool
    {
        $sql = $this->db->prepare('DELETE FROM %i WHERE option_name = %s AND option_value = %s', $this->db->options, $name, $value);
        $rows = is_string($sql) ? $this->db->query($sql) : false;
        $this->forget($name);

        return $rows === 1;
    }

    private function forget(string $name): void
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($name, 'options');
            wp_cache_delete('notoptions', 'options');
            wp_cache_delete('alloptions', 'options');
        }
    }
}
