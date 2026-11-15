<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Storage;

/**
 * Candidate sets in protected post meta, newest first, last N kept.
 * The meta is not revisioned and not shown in REST post responses.
 */
final class SetStore
{
    public const META_KEY = '_headline_options_sets';

    /**
     * @return list<array<string, mixed>>
     */
    public function all(int $postId): array
    {
        $value = get_post_meta($postId, self::META_KEY, true);
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @param array<string, mixed> $set
     */
    public function add(int $postId, array $set, int $keep): bool
    {
        $sets = $this->all($postId);
        array_unshift($sets, $set);
        $sets = array_slice($sets, 0, max(1, $keep));

        return $this->write($postId, $sets);
    }

    /**
     * Record a choice on one set. False when the set or index is unknown or the write failed.
     */
    public function choose(int $postId, string $setId, int $index, int $userId, string $at): bool
    {
        $sets = $this->all($postId);
        foreach ($sets as $i => $set) {
            if (($set['id'] ?? null) !== $setId) {
                continue;
            }
            $candidates = is_array($set['candidates'] ?? null) ? $set['candidates'] : [];
            if (!array_key_exists($index, $candidates)) {
                return false;
            }
            $sets[$i]['chosen_index'] = $index;
            $sets[$i]['chosen_by'] = $userId;
            $sets[$i]['chosen_at'] = $at;

            return $this->write($postId, $sets);
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $sets
     */
    private function write(int $postId, array $sets): bool
    {
        // update_post_meta() unslashes its value, so a backslash in a
        // candidate would be lost without this.
        $result = update_post_meta($postId, self::META_KEY, function_exists('wp_slash') ? wp_slash($sets) : $sets);

        return $result !== false;
    }
}
