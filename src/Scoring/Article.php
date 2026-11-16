<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring;

use IndianOpinion\HeadlineOptions\Text\Numbers;
use IndianOpinion\HeadlineOptions\Text\Utf8;

/**
 * The article a headline is checked against: plain text and its terms.
 */
final class Article
{
    public readonly string $folded;
    /** @var array<string, true> */
    public readonly array $digitNumbers;
    /** @var array<string, true> */
    public readonly array $numberWords;
    public readonly string $language;

    /**
     * @param list<string>          $tags
     * @param list<string>          $categories
     * @param list<string>          $knownEntities
     * @param array<string, string> $numberWordMap word => digit value, for this language
     */
    public function __construct(
        public readonly string $text,
        public readonly array $tags = [],
        public readonly array $categories = [],
        string $language = 'en',
        public readonly array $knownEntities = [],
        array $numberWordMap = [],
        public readonly string $contentHash = '',
    ) {
        $this->language = $language === '' ? 'en' : $language;
        $this->folded = Utf8::fold(Utf8::foldQuotes($text));
        $this->digitNumbers = array_fill_keys(Numbers::digitTokens($text), true);
        $this->numberWords = array_fill_keys(Numbers::wordTokens($text, $numberWordMap), true);
    }

    /** Primary language subtag in lower case: `en_GB` gives `en`. */
    public function languageCode(): string
    {
        return self::primarySubtag($this->language);
    }

    public static function primarySubtag(string $language): string
    {
        $parts = preg_split('/[_\-]/', strtolower(trim($language)));

        return ($parts !== false && $parts[0] !== '') ? $parts[0] : 'en';
    }

    /**
     * True when the phrase appears in the text as whole words, ignoring case.
     */
    public function containsPhrase(string $phrase): bool
    {
        $phrase = Utf8::fold(Utf8::foldQuotes(trim($phrase)));
        if ($phrase === '') {
            return false;
        }
        $pattern = '/(?<![\p{L}\p{M}\p{Nd}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{M}\p{Nd}])/u';

        return preg_match($pattern, $this->folded) === 1;
    }

    /**
     * True when the word appears in the text in exactly this case, as a whole word.
     */
    public function containsExact(string $word): bool
    {
        $pattern = '/(?<![\p{L}\p{M}\p{Nd}])' . preg_quote($word, '/') . '(?![\p{L}\p{M}\p{Nd}])/u';

        return preg_match($pattern, $this->text) === 1;
    }
}
