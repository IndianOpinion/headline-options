<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Tests\Unit;

use IndianOpinion\HeadlineOptions\Generation\CandidateProcessor;
use IndianOpinion\HeadlineOptions\Scoring\ScorerFactory;
use IndianOpinion\HeadlineOptions\Settings;
use IndianOpinion\HeadlineOptions\Tests\Stubs\WpState;
use IndianOpinion\HeadlineOptions\Text\Cleaner;
use PHPUnit\Framework\TestCase;

/**
 * T18 to T21, T23, T24: candidates after the model answers.
 */
final class CandidateProcessorTest extends TestCase
{
    private const BODY = 'The Reserve Bank of India kept the repo rate at 6.5% on Wednesday. Governor Sanjay Malhotra said inflation eased.';

    protected function setUp(): void
    {
        WpState::reset();
    }

    /**
     * @param list<array{headline: string, angle: string}|string> $items
     * @return array{candidates: list<\IndianOpinion\HeadlineOptions\Generation\Candidate>, notes: list<array{code: string, message: string}>, returned: int}
     */
    private function process(array $items, int $count = 5, string $current = 'Repo rate unchanged'): array
    {
        $factory = new ScorerFactory(new Settings());
        $scorer = $factory->create('en_US');
        $data = ['candidates' => array_map(static fn ($i): array => is_array($i) ? $i : ['headline' => $i, 'angle' => 'the news'], $items)];

        return (new CandidateProcessor(new Cleaner(), $scorer))->process($data, $factory->article(self::BODY, 'en_US'), $current, $count);
    }

    /** @param list<\IndianOpinion\HeadlineOptions\Generation\Candidate> $c */
    private static function texts(array $c): array
    {
        return array_map(static fn ($x): string => $x->text, $c);
    }

    public function testT18EmptyAndDuplicatesDropped(): void
    {
        $r = $this->process(['  ""  ', 'Reserve Bank holds repo rate at 6.5%', 'reserve bank holds REPO rate at 6.5%!', '**  **']);
        self::assertSame(['Reserve Bank holds repo rate at 6.5%'], self::texts($r['candidates']));
    }

    public function testT19FirstDuplicateKept(): void
    {
        $r = $this->process(['Malhotra keeps the repo rate at 6.5%.', 'MALHOTRA keeps the repo rate at 6.5%']);
        self::assertCount(1, $r['candidates']);
        self::assertSame('Malhotra keeps the repo rate at 6.5%', $r['candidates'][0]->text);
    }

    public function testT20CurrentHeadlineDroppedWithNote(): void
    {
        $r = $this->process(['"Repo rate unchanged."', 'Reserve Bank holds repo rate at 6.5%']);
        self::assertSame(['Reserve Bank holds repo rate at 6.5%'], self::texts($r['candidates']));
        self::assertContains('repeated_current', array_column($r['notes'], 'code'));
    }

    public function testT21TooLongDroppedNotTruncated(): void
    {
        $long = str_repeat('Reserve Bank ', 20);
        $r = $this->process([$long, 'Reserve Bank holds repo rate at 6.5%']);
        self::assertSame(['Reserve Bank holds repo rate at 6.5%'], self::texts($r['candidates']));
        self::assertContains('too_long', array_column($r['notes'], 'code'));
        foreach ($r['candidates'] as $c) {
            self::assertStringNotContainsString(substr(trim($long), 0, 60), $c->text);
        }
    }

    public function testT22SevenForFiveKeepsFiveAndThreeGivesNote(): void
    {
        $seven = [];
        for ($i = 1; $i <= 7; ++$i) {
            $seven[] = 'Reserve Bank decision number ' . str_repeat('x', $i) . ' on rates';
        }
        $r = $this->process($seven, 5);
        self::assertCount(5, $r['candidates']);
        self::assertSame(5, $r['returned']);
        self::assertSame([], $r['notes']);

        $r3 = $this->process(array_slice($seven, 0, 3), 5);
        self::assertSame(3, $r3['returned']);
        self::assertContains('The model returned 3 of 5.', array_column($r3['notes'], 'message'));
    }

    public function testT23AngleCleanedAndCapped(): void
    {
        $angle = '**Angle:** ' . str_repeat('consequence ', 10);
        $r = $this->process([['headline' => 'Reserve Bank holds repo rate at 6.5%', 'angle' => $angle]]);
        $c = $r['candidates'][0];
        self::assertStringNotContainsString('**', $c->angle);
        self::assertLessThanOrEqual(60, mb_strlen($c->angle));
        $plain = $this->process([['headline' => 'Reserve Bank holds repo rate at 6.5%', 'angle' => '']]);
        self::assertSame($plain['candidates'][0]->score->total, $c->score->total);
    }

    public function testT24SortedByScoreTiesInModelOrder(): void
    {
        $r = $this->process([
            '12 dead as Modi visits Mumbai!!',
            'Reserve Bank holds repo rate at 6.5%',
            'Reserve Bank keeps repo rate at 6.5%',
        ]);
        $totals = array_map(static fn ($c): ?int => $c->score->total, $r['candidates']);
        $sorted = $totals;
        rsort($sorted);
        self::assertSame($sorted, $totals);
        self::assertSame('Reserve Bank holds repo rate at 6.5%', $r['candidates'][0]->text);
        self::assertSame('Reserve Bank keeps repo rate at 6.5%', $r['candidates'][1]->text);
        self::assertSame(1, $r['candidates'][0]->modelOrder);
    }
}
