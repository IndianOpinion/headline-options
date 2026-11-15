<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Tests\Unit;

use IndianOpinion\HeadlineOptions\Text\Cleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T17: cleaning of model-written candidates (case 17).
 */
final class CleanerTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function cases(): array
    {
        return [
            'numbered dot' => ['1. RBI holds rate', 'RBI holds rate'],
            'numbered paren' => ['2) RBI holds rate', 'RBI holds rate'],
            'dash bullet' => ['- RBI holds rate', 'RBI holds rate'],
            'star bullet' => ['* RBI holds rate', 'RBI holds rate'],
            'round bullet' => ["\u{2022} RBI holds rate", 'RBI holds rate'],
            'hash' => ['## RBI holds rate', 'RBI holds rate'],
            'straight double quotes' => ['"RBI holds rate"', 'RBI holds rate'],
            'straight single quotes' => ["'RBI holds rate'", 'RBI holds rate'],
            'curly double quotes' => ["\u{201C}RBI holds rate\u{201D}", 'RBI holds rate'],
            'curly single quotes' => ["\u{2018}RBI holds rate\u{2019}", 'RBI holds rate'],
            'low-high quotes' => ["\u{201E}RBI holds rate\u{201C}", 'RBI holds rate'],
            'guillemets' => ["\u{00AB}RBI holds rate\u{00BB}", 'RBI holds rate'],
            'corner brackets' => ["\u{300C}RBI holds rate\u{300D}", 'RBI holds rate'],
            'two quoted parts kept' => ['"Yes" and "no" from RBI', '"Yes" and "no" from RBI'],
            'markdown bold' => ['**RBI holds rate**', 'RBI holds rate'],
            'markdown underscores' => ['__RBI holds rate__', 'RBI holds rate'],
            'backticks' => ['`RBI holds rate`', 'RBI holds rate'],
            'html tags and entities' => ['<b>Tata &amp; Sons</b> report profit', 'Tata & Sons report profit'],
            'headline prefix' => ['Headline: RBI holds rate', 'RBI holds rate'],
            'title prefix any case' => ['TITLE: RBI holds rate', 'RBI holds rate'],
            'nested everything' => ['1. **Headline:** "RBI holds rate."', 'RBI holds rate'],
            'trailing full stop' => ['RBI holds rate.', 'RBI holds rate'],
            'question kept' => ['Will RBI cut rates?', 'Will RBI cut rates?'],
            'exclamation kept' => ['RBI holds rate!', 'RBI holds rate!'],
            'ellipsis kept' => ['RBI holds rate...', 'RBI holds rate...'],
            'abbreviation stop kept' => ['Talks with the U.S.', 'Talks with the U.S.'],
            'whitespace collapsed' => ["  RBI \t holds\n rate  ", 'RBI holds rate'],
            'controls removed' => ["RBI\u{0007} holds\u{200B} rate", 'RBI holds rate'],
            'year not a list marker' => ['2026 budget lifts spending', '2026 budget lifts spending'],
            'initial not a list marker' => ['J. K. Rowling releases new book', 'J. K. Rowling releases new book'],
        ];
    }

    #[DataProvider('cases')]
    public function testCleans(string $in, string $out): void
    {
        self::assertSame($out, (new Cleaner())->clean($in));
    }

    public function testZwjInsideDevanagariWordIsKept(): void
    {
        // क्‍ष with a ZWJ after the virama: Indic text needs it.
        $word = "\u{0915}\u{094D}\u{200D}\u{0937}";
        $clean = (new Cleaner())->clean('"' . $word . ' समाचार"');
        self::assertStringContainsString("\u{200D}", $clean);
        self::assertSame($word . ' समाचार', $clean);
    }

    public function testZwjOutsideAWordIsRemoved(): void
    {
        self::assertSame('RBI holds', (new Cleaner())->clean("\u{200D}RBI holds\u{200C}"));
    }

    public function testEmptyAfterCleaning(): void
    {
        self::assertSame('', (new Cleaner())->clean('  **""**  '));
    }

    public function testPrefixListIsReplaceable(): void
    {
        $cleaner = new Cleaner(['Titel']);
        self::assertSame('Neue Regeln', $cleaner->clean('Titel: Neue Regeln'));
        self::assertSame('Headline: Neue Regeln', $cleaner->clean('Headline: Neue Regeln'));
    }
}
