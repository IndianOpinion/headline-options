<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring\Rules;

use IndianOpinion\HeadlineOptions\Scoring\Article;
use IndianOpinion\HeadlineOptions\Scoring\Headline;
use IndianOpinion\HeadlineOptions\Scoring\RuleResult;
use IndianOpinion\HeadlineOptions\Scoring\Status;
use IndianOpinion\HeadlineOptions\Text\Utf8;

/**
 * Case 37: stock clickbait phrases. One match warns, two or more fail. A
 * language with no list is `na`.
 */
final class ClickbaitRule extends AbstractRule
{
    /**
     * @param array<string, list<string>> $terms phrases keyed by primary language subtag
     */
    public function __construct(int $weight, private readonly array $terms)
    {
        parent::__construct($weight);
    }

    public function id(): string
    {
        return 'clickbait';
    }

    public function evaluate(Headline $headline, Article $article): RuleResult
    {
        $lang = $article->languageCode();
        $list = $this->terms[$lang] ?? [];
        if ($list === []) {
            return $this->na(sprintf('No clickbait list for language "%s".', $lang));
        }
        $text = Utf8::fold(Utf8::foldQuotes($headline->text));
        $found = [];
        foreach ($list as $phrase) {
            $needle = Utf8::fold(Utf8::foldQuotes(trim($phrase)));
            if ($needle === '' || in_array($needle, $found, true)) {
                continue;
            }
            $pattern = '/(?<![\p{L}\p{M}\p{Nd}])' . preg_quote($needle, '/') . '(?![\p{L}\p{M}\p{Nd}])/u';
            if (preg_match($pattern, $text) === 1) {
                // Skip a phrase already covered by a longer match ("blow your mind" inside "will blow your mind").
                $covered = false;
                foreach ($found as $existing) {
                    if (str_contains($existing, $needle) || str_contains($needle, $existing)) {
                        $covered = true;
                        break;
                    }
                }
                if (!$covered) {
                    $found[] = $needle;
                }
            }
        }
        if ($found === []) {
            return $this->result(Status::Pass, 'No stock clickbait phrases.');
        }
        $quoted = implode(', ', array_map(static fn (string $p): string => '"' . $p . '"', $found));
        if (count($found) === 1) {
            return $this->result(Status::Warn, 'Clickbait phrase: ' . $quoted . '.', ['matched' => $found]);
        }

        return $this->result(Status::Fail, 'Clickbait phrases: ' . $quoted . '.', ['matched' => $found]);
    }
}
