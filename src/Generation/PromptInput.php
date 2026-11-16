<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Generation;

/**
 * The values a prompt is rendered from.
 */
final class PromptInput
{
    public function __construct(
        public readonly int $count,
        public readonly string $language,
        public readonly int $maxLength,
        public readonly string $currentTitle,
        public readonly string $excerpt,
        public readonly string $text,
        public readonly string $styleNote,
    ) {
    }

    /** @return array{count: int, language: string, max_length: int, current_title: string, excerpt: string, text: string, style_note: string} */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'language' => $this->language,
            'max_length' => $this->maxLength,
            'current_title' => $this->currentTitle,
            'excerpt' => $this->excerpt,
            'text' => $this->text,
            'style_note' => $this->styleNote,
        ];
    }
}
