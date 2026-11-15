<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Tests\Unit;

use IndianOpinion\HeadlineOptions\Scoring\Article;
use IndianOpinion\HeadlineOptions\Scoring\Headline;
use IndianOpinion\HeadlineOptions\Scoring\Rule;
use IndianOpinion\HeadlineOptions\Scoring\RuleResult;
use IndianOpinion\HeadlineOptions\Scoring\Rules\ScriptMatchRule;
use IndianOpinion\HeadlineOptions\Scoring\ScoreResult;
use IndianOpinion\HeadlineOptions\Scoring\ScorerFactory;
use IndianOpinion\HeadlineOptions\Scoring\Status;
use IndianOpinion\HeadlineOptions\Settings;
use IndianOpinion\HeadlineOptions\Tests\Stubs\WpState;
use IndianOpinion\HeadlineOptions\Text\Length;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T25, T33 to T46, T63, T64: the rules and the total.
 */
final class RulesTest extends TestCase
{
    private const BODY = 'The Reserve Bank of India kept the repo rate at 6.5% on Wednesday. Governor Sanjay Malhotra said '
        . 'inflation had eased. At least ten people protested outside. Prices rose 1,00,000 rupees for 1,200 homes. '
        . 'The NASA report was cited. Growth was 3,5 per cent in the German estimate. Records go back to 1998.';

    protected function setUp(): void
    {
        WpState::reset();
    }

    private function score(string $headline, string $body = self::BODY, array $tags = [], string $lang = 'en_US', ?string $current = null): ScoreResult
    {
        $factory = new ScorerFactory(new Settings());

        return $factory->create($lang)->score($headline, $factory->article($body, $lang, $tags), $current);
    }

    private function rule(ScoreResult $r, string $id): RuleResult
    {
        foreach ($r->results as $result) {
            if ($result->ruleId === $id) {
                return $result;
            }
        }
        self::fail('No rule ' . $id);
    }

    public function testT25EveryRuleHasStatusPointsAndReason(): void
    {
        $r = $this->score('RBI keeps repo rate at 6.5% as inflation eases');
        $array = $r->toArray();
        self::assertSame(['headline', 'total', 'band', 'length', 'length_unit', 'words', 'closeness', 'rules'], array_keys($array));
        $ids = array_column($array['rules'], 'id');
        self::assertSame(['length', 'clickbait', 'withheld_subject', 'numeral_presence', 'numeral_support', 'entity_support', 'shouting', 'punctuation', 'emoji', 'script_match'], $ids);
        foreach ($array['rules'] as $rule) {
            self::assertContains($rule['status'], ['pass', 'warn', 'fail', 'na']);
            self::assertIsInt($rule['earned']);
            self::assertIsInt($rule['possible']);
            self::assertNotSame('', $rule['reason']);
        }
        self::assertIsInt($array['total']);
        self::assertGreaterThanOrEqual(0, $array['total']);
        self::assertLessThanOrEqual(100, $array['total']);
    }

    public function testT33Deterministic(): void
    {
        $a = json_encode($this->score('12 dead as Modi visits Mumbai!!', self::BODY, ['Monetary policy'], 'en_US', 'Repo rate')->toArray());
        $b = json_encode($this->score('12 dead as Modi visits Mumbai!!', self::BODY, ['Monetary policy'], 'en_US', 'Repo rate')->toArray());
        self::assertSame($a, $b);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function lengths(): array
    {
        return ['19' => [19, 'fail'], '20' => [20, 'warn'], '29' => [29, 'warn'], '30' => [30, 'pass'], '70' => [70, 'pass'], '71' => [71, 'warn'], '90' => [90, 'warn'], '91' => [91, 'fail']];
    }

    #[DataProvider('lengths')]
    public function testT34LengthBands(int $n, string $status): void
    {
        $headline = substr(str_repeat('abcd ', 30), 0, $n - 1) . 'x';
        $r = $this->rule($this->score($headline), 'length');
        self::assertSame($n, $r->data['length']);
        self::assertSame($status, $r->status->value);
    }

    public function testT34HindiLengthInGraphemesAndFallback(): void
    {
        $hindi = 'रिज़र्व बैंक ने रेपो दर नहीं बदली';
        $intl = new Headline($hindi, new Length(true));
        $fallback = new Headline($hindi, new Length(false));
        self::assertLessThan(mb_strlen($hindi), $fallback->length);
        if (function_exists('grapheme_strlen')) {
            self::assertSame(grapheme_strlen($hindi), $intl->length);
            self::assertSame('grapheme', $intl->lengthUnit);
        }
        self::assertSame('codepoint', $fallback->lengthUnit);
        $latin = 'Repo rate unchanged';
        self::assertSame((new Headline($latin, new Length(true)))->length, (new Headline($latin, new Length(false)))->length);
    }

    public function testT35Clickbait(): void
    {
        self::assertSame('warn', $this->rule($this->score('Shocking turn as the Reserve Bank holds repo rate'), 'clickbait')->status->value);
        $two = $this->rule($this->score("You won\u{2019}t believe what happened next at the Reserve Bank"), 'clickbait');
        self::assertSame('fail', $two->status->value);
        self::assertStringContainsString("you won't believe", $two->reason);
        self::assertSame('pass', $this->rule($this->score('Reserve Bank holds repo rate at 6.5%'), 'clickbait')->status->value);
        self::assertSame('na', $this->rule($this->score('Reserve Bank holds repo rate', self::BODY, [], 'fr_FR'), 'clickbait')->status->value);
    }

    public function testT35ClickbaitListsByFilter(): void
    {
        add_filter('headline_options_clickbait_terms', static fn (array $t): array => $t + ['fr' => ['incroyable']]);
        self::assertSame('warn', $this->rule($this->score('Une hausse incroyable des prix', self::BODY, [], 'fr_FR'), 'clickbait')->status->value);
    }

    public function testT36WithheldSubject(): void
    {
        $r = $this->rule($this->score('This is why the Reserve Bank held rates'), 'withheld_subject');
        self::assertSame('warn', $r->status->value);
        self::assertSame('warn', $this->rule($this->score('They did it again at the Reserve Bank'), 'withheld_subject')->status->value);
        self::assertSame('pass', $this->rule($this->score('Reserve Bank holds repo rate at 6.5%'), 'withheld_subject')->status->value);
    }

    public function testT37NumeralPresence(): void
    {
        self::assertSame('na', $this->rule($this->score('Reserve Bank holds repo rate'), 'numeral_presence')->status->value);
        $one = $this->rule($this->score('Reserve Bank holds repo rate at 6.5%'), 'numeral_presence');
        self::assertSame('pass', $one->status->value);
        self::assertSame(5, $one->earned);
    }

    public function testT38NumeralSupport(): void
    {
        $fail = $this->rule($this->score('12 dead as Reserve Bank holds rates'), 'numeral_support');
        self::assertSame('fail', $fail->status->value);
        self::assertStringContainsString('12', $fail->reason);
        self::assertSame('pass', $this->rule($this->score('Prices rise by 100000 rupees'), 'numeral_support')->status->value);
        self::assertSame('pass', $this->rule($this->score('1200 homes see prices rise'), 'numeral_support')->status->value);
        self::assertSame('pass', $this->rule($this->score('रेपो दर ६.५% पर'), 'numeral_support')->status->value);
        self::assertSame('pass', $this->rule($this->score('Growth at 3,5 per cent'), 'numeral_support')->status->value);
        self::assertSame('fail', $this->rule($this->score('Growth at 35 per cent'), 'numeral_support')->status->value);
        self::assertSame('pass', $this->rule($this->score('Ten protest at the Reserve Bank'), 'numeral_support')->status->value);
        self::assertSame('pass', $this->rule($this->score('Records since 1998 show change'), 'numeral_support')->status->value);
        $year = $this->rule($this->score('Records since 1997 show change'), 'numeral_support');
        self::assertSame('fail', $year->status->value);
        self::assertStringContainsString('1997 (year)', $year->reason);
    }

    public function testT39EntitySupport(): void
    {
        $fail = $this->rule($this->score('Reserve Bank holds rates as Modi visits Mumbai'), 'entity_support');
        self::assertSame('fail', $fail->status->value);
        self::assertStringContainsString('Modi', $fail->reason);
        self::assertSame('pass', $this->rule($this->score("Rates held at Malhotra\u{2019}s first meeting"), 'entity_support')->status->value);
        $tag = $this->rule($this->score('What monetary policy means for you', self::BODY, ['Monetary policy']), 'entity_support');
        self::assertSame('fail', $tag->status->value);
        self::assertStringContainsString('Monetary policy', $tag->reason);
        $tagInBody = $this->rule($this->score('repo rate stays put', self::BODY, ['Repo rate']), 'entity_support');
        self::assertSame('pass', $tagInBody->status->value);
    }

    public function testT40CaselessHeadlineWithoutLatinOrTagsIsNa(): void
    {
        $r = $this->rule($this->score('रिज़र्व बैंक ने रेपो दर नहीं बदली', 'रिज़र्व बैंक ने बुधवार को रेपो दर में कोई बदलाव नहीं किया।', [], 'hi_IN'), 'entity_support');
        self::assertSame('na', $r->status->value);
        $latin = $this->rule($this->score('RBI ने रेपो दर नहीं बदली', 'रिज़र्व बैंक ने बुधवार को रेपो दर में कोई बदलाव नहीं किया।', [], 'hi_IN'), 'entity_support');
        self::assertSame('fail', $latin->status->value);
        add_filter('headline_options_known_entities', static fn (): array => ['रिज़र्व बैंक']);
        $known = $this->rule($this->score('रिज़र्व बैंक ने रेपो दर नहीं बदली', 'रिज़र्व बैंक ने बुधवार को रेपो दर में कोई बदलाव नहीं किया।', [], 'hi_IN'), 'entity_support');
        self::assertSame('pass', $known->status->value);
    }

    public function testT41Shouting(): void
    {
        self::assertSame('warn', $this->rule($this->score('Reserve Bank HOLDS RATES at meeting'), 'shouting')->status->value);
        self::assertSame('pass', $this->rule($this->score('NASA report cited by Reserve Bank'), 'shouting')->status->value);
        self::assertSame('fail', $this->rule($this->score('RESERVE BANK HOLDS RATES TODAY'), 'shouting')->status->value);
    }

    public function testT42Punctuation(): void
    {
        foreach (['Rates held!!', 'Rates held?!', "Rates held\u{2026} for now", 'Rates held... for now', 'Rates held!'] as $h) {
            self::assertSame('warn', $this->rule($this->score($h), 'punctuation')->status->value, $h);
        }
        $q = $this->score('Will the Reserve Bank cut rates?');
        self::assertSame('pass', $this->rule($q, 'punctuation')->status->value);
        self::assertSame('warn', $this->rule($q, 'withheld_subject')->status->value);
    }

    public function testT43EmojiAndStyledLetters(): void
    {
        self::assertSame('fail', $this->rule($this->score("Rates held \u{1F525}"), 'emoji')->status->value);
        self::assertSame('fail', $this->rule($this->score("\u{1D411}\u{1D401}\u{1D408} holds rates"), 'emoji')->status->value);
        self::assertSame('pass', $this->rule($this->score("\u{20B9}6.5 lakh crore and 40\u{00B0} heat"), 'emoji')->status->value);
    }

    public function testT44ScriptMatchWithScriptShare(): void
    {
        self::assertTrue(ScriptMatchRule::available(), 'script-share is a dev dependency');
        $english = 'The Reserve Bank of India kept the repo rate unchanged on Wednesday and said prices had eased. '
            . 'A trader said: "बाज़ार में कोई बदलाव नहीं है।" Officials will meet again in December.';
        self::assertSame('fail', $this->rule($this->score('रिज़र्व बैंक ने रेपो दर नहीं बदली', $english), 'script_match')->status->value);
        self::assertSame('pass', $this->rule($this->score('Reserve Bank keeps the repo rate unchanged', $english), 'script_match')->status->value);
    }

    public function testT44ScriptMatchWithoutScriptShareIsNa(): void
    {
        $rule = new ScriptMatchRule(10, null);
        $ref = new \ReflectionProperty($rule, 'dominant');
        $ref->setValue($rule, null);
        $r = $rule->evaluate(new Headline('Reserve Bank', new Length()), new Article('The Reserve Bank'));
        self::assertSame(Status::Na, $r->status);
        self::assertSame('Install script-share for the script check.', $r->reason);
    }

    public function testT45ClosenessReportedNotScored(): void
    {
        $with = $this->score('Reserve Bank holds repo rate', self::BODY, [], 'en_US', 'Reserve Bank keeps repo rate');
        $without = $this->score('Reserve Bank holds repo rate');
        self::assertNotNull($with->closeness);
        self::assertGreaterThan(0.0, $with->closeness);
        self::assertLessThan(1.0, $with->closeness);
        self::assertSame($without->total, $with->total);
    }

    public function testT46FilterRuleRunsAndThrowingRuleIsNa(): void
    {
        $custom = new class () implements Rule {
            public function id(): string
            {
                return 'custom';
            }

            public function weight(): int
            {
                return 10;
            }

            public function evaluate(Headline $headline, Article $article): RuleResult
            {
                return RuleResult::of('custom', Status::Pass, 10, 'ok');
            }
        };
        $broken = new class () implements Rule {
            public function id(): string
            {
                return 'broken';
            }

            public function weight(): int
            {
                return 10;
            }

            public function evaluate(Headline $headline, Article $article): RuleResult
            {
                throw new \RuntimeException('boom');
            }
        };
        $logged = [];
        add_action('headline_options_log', static function (string $level, string $message, array $context) use (&$logged): void {
            $logged[] = $message;
        });
        add_filter('headline_options_rules', static fn (array $rules): array => array_merge($rules, [$custom, $broken]));
        $r = $this->score('Reserve Bank holds repo rate at 6.5%');
        self::assertSame('pass', $this->rule($r, 'custom')->status->value);
        $b = $this->rule($r, 'broken');
        self::assertSame('na', $b->status->value);
        self::assertSame('rule error', $b->reason);
        self::assertSame('pass', $this->rule($r, 'length')->status->value);
        self::assertContains('rule error', $logged);
    }

    public function testT63EmptyCurrentHasNoCloseness(): void
    {
        self::assertNull($this->score('Reserve Bank holds repo rate', self::BODY, [], 'en_US', '')->closeness);
    }

    public function testT64EntitiesDecodedBeforeScoring(): void
    {
        $r = $this->score('Tata &amp; Sons say profit rose &#8217;sharply&#8217;', 'Tata & Sons said profit rose sharply.');
        self::assertSame("Tata & Sons say profit rose \u{2019}sharply\u{2019}", $r->headline);
    }

    public function testWeightsAndBandsAreFilterable(): void
    {
        add_filter('headline_options_weights', static fn (array $w): array => ['length' => 0] + $w);
        add_filter('headline_options_bands', static fn (): array => ['good' => 99, 'fair' => 98]);
        $r = $this->score('12 dead as Modi visits Mumbai');
        self::assertSame(0, $this->rule($r, 'length')->possible);
        self::assertLessThan(98, $r->total);
        self::assertSame('weak', $r->band);
    }

    public function testAllNaGivesNullTotal(): void
    {
        add_filter('headline_options_rules', static fn (): array => []);
        $r = $this->score('Anything');
        self::assertNull($r->total);
        self::assertNull($r->band);
    }
}
