<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring;

use IndianOpinion\HeadlineOptions\Hooks;
use IndianOpinion\HeadlineOptions\Logger;
use IndianOpinion\HeadlineOptions\Scoring\Rules\ClickbaitRule;
use IndianOpinion\HeadlineOptions\Scoring\Rules\EmojiRule;
use IndianOpinion\HeadlineOptions\Scoring\Rules\EntitySupportRule;
use IndianOpinion\HeadlineOptions\Scoring\Rules\LengthRule;
use IndianOpinion\HeadlineOptions\Scoring\Rules\NumeralPresenceRule;
use IndianOpinion\HeadlineOptions\Scoring\Rules\NumeralSupportRule;
use IndianOpinion\HeadlineOptions\Scoring\Rules\PunctuationRule;
use IndianOpinion\HeadlineOptions\Scoring\Rules\ScriptMatchRule;
use IndianOpinion\HeadlineOptions\Scoring\Rules\ShoutingRule;
use IndianOpinion\HeadlineOptions\Scoring\Rules\WithheldSubjectRule;
use IndianOpinion\HeadlineOptions\Settings;
use IndianOpinion\HeadlineOptions\Text\Length;

/**
 * Builds the scorer and article objects with every list, weight and rule run
 * through its filter.
 */
final class ScorerFactory
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ?Length $length = null,
        /** @var (callable(string): ?string)|null */
        private readonly mixed $scriptDetector = null,
    ) {
    }

    public function create(string $language): Scorer
    {
        $length = $this->length ?? new Length();
        $weights = self::intMap(Hooks::filter('headline_options_weights', Lists::weights()), Lists::weights());
        $bounds = Hooks::filter('headline_options_length_bounds', [
            'pass' => $this->settings->range('length_pass'),
            'warn' => $this->settings->range('length_warn'),
        ]);
        if (!is_array($bounds) || !self::isPair($bounds['pass'] ?? null) || !self::isPair($bounds['warn'] ?? null)) {
            $bounds = ['pass' => $this->settings->range('length_pass'), 'warn' => $this->settings->range('length_warn')];
        }
        /** @var array{pass: array{0: int, 1: int}, warn: array{0: int, 1: int}} $bounds */
        $clickbait = self::listMap(Hooks::filter('headline_options_clickbait_terms', Lists::clickbait()));
        $withheld = self::listMap(Hooks::filter('headline_options_withheld_patterns', Lists::withheldSubject()));
        $numberWords = self::numberWords();
        $detector = is_callable($this->scriptDetector) ? $this->scriptDetector : null;

        $rules = [
            new LengthRule($weights['length'], $bounds),
            new ClickbaitRule($weights['clickbait'], $clickbait),
            new WithheldSubjectRule($weights['withheld_subject'], $withheld),
            new NumeralPresenceRule($weights['numeral_presence']),
            new NumeralSupportRule($weights['numeral_support'], $numberWords),
            new EntitySupportRule($weights['entity_support']),
            new ShoutingRule($weights['shouting']),
            new PunctuationRule($weights['punctuation']),
            new EmojiRule($weights['emoji']),
            new ScriptMatchRule($weights['script_match'], $detector),
        ];
        $filtered = Hooks::filter('headline_options_rules', $rules, $language);
        $rules = is_array($filtered) ? array_values(array_filter($filtered, static fn ($r): bool => $r instanceof Rule)) : $rules;

        $bands = Hooks::filter('headline_options_bands', ['good' => 75, 'fair' => 50]);
        if (!is_array($bands) || !is_int($bands['good'] ?? null) || !is_int($bands['fair'] ?? null) || $bands['fair'] > $bands['good']) {
            $bands = ['good' => 75, 'fair' => 50];
        }
        /** @var array{good: int, fair: int} $bands */

        return new Scorer(
            $rules,
            $length,
            $numberWords[Article::primarySubtag($language)] ?? [],
            $bands,
            static function (string $message, array $context): void {
                Logger::log('warning', $message, $context);
            },
        );
    }

    /**
     * @param list<string> $tags
     * @param list<string> $categories
     * @param list<string> $extraEntities names returned by the model with its candidates
     */
    public function article(string $plainText, string $language, array $tags = [], array $categories = [], string $contentHash = '', array $extraEntities = []): Article
    {
        $known = Hooks::filter('headline_options_known_entities', [], $language);
        $known = is_array($known) ? array_values(array_filter($known, 'is_string')) : [];
        $known = array_values(array_unique(array_merge($known, $extraEntities)));
        $words = self::numberWords();

        return new Article($plainText, $tags, $categories, $language, $known, $words[Article::primarySubtag($language)] ?? [], $contentHash);
    }

    /** @return array<string, array<string, string>> */
    public static function numberWords(): array
    {
        $raw = Hooks::filter('headline_options_number_words', Lists::numberWords());
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $lang => $map) {
            if (!is_string($lang) || !is_array($map)) {
                continue;
            }
            foreach ($map as $word => $value) {
                if (is_string($word) && (is_string($value) || is_int($value)) && preg_match('/^\d+$/', (string) $value) === 1) {
                    $out[strtolower($lang)][mb_convert_case($word, MB_CASE_FOLD, 'UTF-8')] = (string) $value;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function listMap(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $lang => $list) {
            if (is_string($lang) && is_array($list)) {
                $out[strtolower($lang)] = array_values(array_filter($list, static fn ($s): bool => is_string($s) && $s !== ''));
            }
        }

        return $out;
    }

    /**
     * @param array<string, int> $defaults
     * @return array<string, int>
     */
    private static function intMap(mixed $raw, array $defaults): array
    {
        $out = $defaults;
        foreach (is_array($raw) ? $raw : [] as $key => $value) {
            if (is_string($key) && is_int($value) && $value >= 0) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function isPair(mixed $v): bool
    {
        return is_array($v) && is_int($v[0] ?? null) && is_int($v[1] ?? null) && $v[0] <= $v[1];
    }
}
