<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring\Rules;

use IndianOpinion\HeadlineOptions\Scoring\Article;
use IndianOpinion\HeadlineOptions\Scoring\Headline;
use IndianOpinion\HeadlineOptions\Scoring\RuleResult;
use IndianOpinion\HeadlineOptions\Scoring\Status;

/**
 * Case 46 and 77: the headline's dominant writing system must match the
 * article's. Uses the optional script-share library; without it the rule is
 * `na`. No script table is shipped here.
 *
 * Headline and body are measured separately, and each needs a majority
 * (more than half its letters) in one script, so an English report that
 * quotes a Hindi line is still English.
 */
final class ScriptMatchRule extends AbstractRule
{
    public const ANALYSER = 'IndianOpinion\\ScriptShare\\Analyser';

    /** @var (callable(string): ?string)|null */
    private $dominant;

    /**
     * @param (callable(string): ?string)|null $dominant Returns the ISO 15924 code of the majority script, or null.
     *                                                   Null detects script-share.
     */
    public function __construct(int $weight, ?callable $dominant = null)
    {
        parent::__construct($weight);
        $this->dominant = $dominant ?? self::detect();
    }

    public static function available(): bool
    {
        return class_exists(self::ANALYSER);
    }

    /**
     * @return (callable(string): ?string)|null
     */
    public static function detect(): ?callable
    {
        if (!self::available()) {
            return null;
        }
        $analyser = new \IndianOpinion\ScriptShare\Analyser();

        return static function (string $text) use ($analyser): ?string {
            try {
                return $analyser->analyse($text)->dominant(0.5, 1)?->value;
            } catch (\Throwable) {
                return null;
            }
        };
    }

    public function id(): string
    {
        return 'script_match';
    }

    public function evaluate(Headline $headline, Article $article): RuleResult
    {
        if ($this->dominant === null) {
            return $this->na('Install script-share for the script check.');
        }
        $head = ($this->dominant)($headline->text);
        if ($head === null) {
            return $this->na('The headline has no majority script.');
        }
        $body = ($this->dominant)($article->text);
        if ($body === null) {
            return $this->na('The article has no majority script.');
        }
        $data = ['headline' => $head, 'article' => $body];
        if ($head !== $body) {
            return $this->result(Status::Fail, sprintf('Headline is in %s, the article in %s.', $head, $body), $data);
        }

        return $this->result(Status::Pass, sprintf('Same script as the article (%s).', $head), $data);
    }
}
