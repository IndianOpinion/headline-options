<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Storage;

/**
 * In-memory option rows, for tests and for running outside WordPress.
 * Writes can be made to fail to exercise the storage failure path.
 */
final class MemoryOptionTable implements OptionTable
{
    /** @var array<string, string> */
    public array $rows = [];
    public bool $failWrites = false;

    public function insertIfAbsent(string $name, string $value): bool
    {
        if ($this->failWrites || array_key_exists($name, $this->rows)) {
            return false;
        }
        $this->rows[$name] = $value;

        return true;
    }

    public function read(string $name): ?string
    {
        return $this->rows[$name] ?? null;
    }

    public function write(string $name, string $value): bool
    {
        if ($this->failWrites) {
            return false;
        }
        $this->rows[$name] = $value;

        return true;
    }

    public function delete(string $name): bool
    {
        unset($this->rows[$name]);

        return true;
    }

    public function deleteIfValue(string $name, string $value): bool
    {
        if (($this->rows[$name] ?? null) !== $value) {
            return false;
        }
        unset($this->rows[$name]);

        return true;
    }
}
