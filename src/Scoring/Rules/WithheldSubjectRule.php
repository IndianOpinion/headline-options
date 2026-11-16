<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring\Rules;

use IndianOpinion\HeadlineOptions\Scoring\Article;
use IndianOpinion\HeadlineOptions\Scoring\Headline;
use IndianOpinion\HeadlineOptions\Scoring\RuleResult;
use IndianOpinion\HeadlineOptions\Scoring\Status;
use IndianOpinion\HeadlineOptions\Text\Regex;
use IndianOpinion\HeadlineOptions\Text\Utf8;

/**
 * Case 38 (and the trailing question mark from case 44): a headline that
 * withholds its subject, or asks a question, is a warn.
 */
final class WithheldSubjectRule extends AbstractRule
{
    /**
     * @param array<string, list<string>> $patterns opening patterns keyed by primary language subtag
     */
    public function __construct(int $weight, private readonly array $patterns)
    {
        parent::__construct($weight);
    }

    public function id(): string
    {
        return 'withheld_subject';
    }

    public function evaluate(Headline $headline, Article $article): RuleResult
    {
        $text = trim(Utf8::foldQuotes($headline->text));
        $lang = $article->languageCode();
        $list = $this->patterns[$lang] ?? [];
        foreach ($list as $pattern) {
            $regex = '/^(?:' . str_replace('/', '\/', $pattern) . ')/iu';
            if (!Regex::isValid($regex)) {
                continue;
            }
            if (preg_match($regex, $text, $m) === 1) {
                return $this->result(Status::Warn, sprintf('Opens with "%s" and withholds the subject.', trim($m[0])), ['matched' => trim($m[0])]);
            }
        }
        if (preg_match('/[?\x{061F}\x{FF1F}]$/u', $text) === 1) {
            return $this->result(Status::Warn, 'Ends with a question; say what the article answers.');
        }
        if ($list === []) {
            return $this->na(sprintf('No withheld-subject patterns for language "%s".', $lang));
        }

        return $this->result(Status::Pass, 'States its subject.');
    }
}
