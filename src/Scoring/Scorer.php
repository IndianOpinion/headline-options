<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring;

use IndianOpinion\HeadlineOptions\Text\Length;
use IndianOpinion\HeadlineOptions\Text\Utf8;

/**
 * Runs every rule over a headline. Deterministic and free: no model call, no
 * database, no clock.
 *
 * A rule that throws is reported as `na` with reason "rule error" and the
 * others still run (case 48).
 */
final class Scorer
{
    /** @var list<Rule> */
    private array $rules;

    /** @var (callable(string, array<string, mixed>): void)|null */
    private $onRuleError;

    /**
     * @param list<Rule>                                         $rules
     * @param array<string, string>                              $numberWords word => digits for the active language
     * @param array{good: int, fair: int}                        $bands
     * @param (callable(string, array<string, mixed>): void)|null $onRuleError receives a message and context when a rule throws
     */
    public function __construct(
        array $rules,
        private readonly Length $length,
        private readonly array $numberWords = [],
        private readonly array $bands = ['good' => 75, 'fair' => 50],
        ?callable $onRuleError = null,
    ) {
        $this->rules = array_values(array_filter($rules, static fn ($r): bool => $r instanceof Rule));
        $this->onRuleError = $onRuleError;
    }

    public function length(): Length
    {
        return $this->length;
    }

    /** @return list<Rule> */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Turns stored or typed text into the text that is scored: entities
     * decoded, whitespace collapsed.
     */
    public static function prepare(string $headline): string
    {
        return Utf8::collapseWhitespace(Utf8::decodeEntities($headline));
    }

    public function headline(string $text): Headline
    {
        return new Headline(self::prepare($text), $this->length, $this->numberWords);
    }

    /**
     * @param string|null $current The current headline, for closeness. Null or empty: no closeness.
     */
    public function score(string $headline, Article $article, ?string $current = null): ScoreResult
    {
        $h = $this->headline($headline);
        $results = [];
        $earned = 0;
        $possible = 0;
        foreach ($this->rules as $rule) {
            try {
                $result = $rule->evaluate($h, $article);
            } catch (\Throwable $e) {
                $id = 'unknown';
                try {
                    $id = $rule->id();
                } catch (\Throwable) {
                    // Keep "unknown".
                }
                if ($this->onRuleError !== null) {
                    ($this->onRuleError)('rule error', ['rule' => $id, 'exception' => get_class($e)]);
                }
                $result = RuleResult::na($id, 'rule error');
            }
            if ($result->status !== Status::Na) {
                $possible += max(0, $result->possible);
                $earned += max(0, min($result->earned, $result->possible));
            }
            $results[] = $result;
        }
        $total = $possible > 0 ? (int) round(100 * $earned / $possible) : null;
        $closeness = null;
        if ($current !== null && self::prepare($current) !== '') {
            $closeness = self::closeness($h->text, self::prepare($current));
        }

        return new ScoreResult(
            $h->text,
            $total,
            $this->band($total),
            $h->length,
            $h->lengthUnit,
            count($h->words),
            $results,
            $closeness,
        );
    }

    public function band(?int $total): ?string
    {
        if ($total === null) {
            return null;
        }
        if ($total >= $this->bands['good']) {
            return 'good';
        }
        if ($total >= $this->bands['fair']) {
            return 'fair';
        }

        return 'weak';
    }

    /**
     * Shared word ratio (Jaccard over case-folded words), two decimals.
     * Reported only; never part of the total (case 47).
     */
    public static function closeness(string $a, string $b): float
    {
        $wa = array_unique(Utf8::words(Utf8::fold($a)));
        $wb = array_unique(Utf8::words(Utf8::fold($b)));
        $union = count(array_unique(array_merge($wa, $wb)));
        if ($union === 0) {
            return 0.0;
        }

        return round(count(array_intersect($wa, $wb)) / $union, 2);
    }
}
