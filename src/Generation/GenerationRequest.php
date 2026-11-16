<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Generation;

/**
 * The editor state a generation works from (case 9): unsaved title, content
 * and excerpt as they are on screen.
 */
final class GenerationRequest
{
    /**
     * @param int|null     $userId     null for WP-CLI (site cap only)
     * @param list<string> $tags
     * @param list<string> $categories
     */
    public function __construct(
        public readonly int $postId,
        public readonly string $title,
        public readonly string $content,
        public readonly string $excerpt,
        public readonly ?int $count,
        public readonly ?int $userId,
        public readonly string $language,
        public readonly array $tags = [],
        public readonly array $categories = [],
    ) {
    }
}
