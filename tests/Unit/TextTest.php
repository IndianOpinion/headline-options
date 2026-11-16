<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Tests\Unit;

use IndianOpinion\HeadlineOptions\Text\Length;
use IndianOpinion\HeadlineOptions\Text\Numbers;
use IndianOpinion\HeadlineOptions\Text\PlainText;
use IndianOpinion\HeadlineOptions\Text\Trimmer;
use IndianOpinion\HeadlineOptions\Text\Utf8;
use PHPUnit\Framework\TestCase;

/**
 * T9, T10, T34 (units), T38 (number tokens), T68.
 */
final class TextTest extends TestCase
{
    private const HINDI = 'भारतीय रिज़र्व बैंक ने रेपो दर में कोई बदलाव नहीं किया';

    public function testT9ContentBecomesPlainTextAndShortcodesAreNotExecuted(): void
    {
        $ran = false;
        $content = '<!-- wp:paragraph --><p>Rates &amp; prices <strong>rose</strong>.</p><!-- /wp:paragraph -->'
            . '[gallery ids="1,2"]<script>alert(1)</script><p>Second&nbsp;line &#8217;here&#8217;</p>[caption id="x"]Pic text[/caption]';
        $text = (new PlainText())->render($content);
        self::assertSame("Rates & prices rose. Second line \u{2019}here\u{2019} Pic text", $text);
        self::assertFalse($ran);
    }

    public function testT9RegisteredShortcodeStripperIsUsed(): void
    {
        $calls = 0;
        $plain = new PlainText(static function (string $t) use (&$calls): string {
            ++$calls;

            return str_replace('[audio]', '', $t);
        });
        self::assertSame('Hello', $plain->render('Hello [audio]'));
        self::assertSame(1, $calls);
    }

    public function testT68GalleryOnlyContentHasNoWords(): void
    {
        $content = '<!-- wp:gallery --><figure class="wp-block-gallery"><img src="a.jpg" alt=""><img src="b.jpg" alt=""></figure><!-- /wp:gallery -->[embed]https://example.org/v[/embed]';
        $text = (new PlainText())->render($content);
        self::assertLessThan(5, PlainText::wordCount($text));
    }

    public function testT10TrimAtSentenceEnd(): void
    {
        $text = 'First sentence here. Second sentence is longer than the limit allows us.';
        self::assertSame('First sentence here.', (new Trimmer(new Length()))->trim($text, 40));
    }

    public function testT10TrimAtWordBoundaryWithoutSentenceEnd(): void
    {
        $text = 'one two three four five six seven';
        self::assertSame('one two three', (new Trimmer(new Length()))->trim($text, 15));
    }

    public function testT10DevanagariCountedInGraphemesAndTrimmedAtDanda(): void
    {
        $length = new Length();
        if ($length->unit() !== Length::GRAPHEME) {
            self::markTestSkipped('ext-intl not loaded');
        }
        $sentence = self::HINDI . '। ';
        $text = str_repeat($sentence, 3);
        $graphemes = grapheme_strlen($sentence);
        self::assertLessThan(mb_strlen($sentence), $graphemes);
        // A limit just under two sentences keeps exactly one, ending at the danda.
        $trimmed = (new Trimmer($length))->trim($text, (int) $graphemes * 2 - 2);
        self::assertSame(self::HINDI . '।', $trimmed);
    }

    public function testT34LengthUnits(): void
    {
        $intl = new Length(true);
        $fallback = new Length(false);
        self::assertSame(Length::CODEPOINT, $fallback->unit());
        // Plain Latin: same in both units.
        self::assertSame(strlen('Repo rate unchanged'), $fallback->count('Repo rate unchanged'));
        self::assertSame($intl->count('Repo rate unchanged'), $fallback->count('Repo rate unchanged'));
        // Hindi: marks are not counted without intl, so it is shorter than code points.
        self::assertLessThan(mb_strlen(self::HINDI), $fallback->count(self::HINDI));
        if ($intl->unit() === Length::GRAPHEME) {
            self::assertSame(grapheme_strlen(self::HINDI), $intl->count(self::HINDI));
            self::assertLessThan(mb_strlen(self::HINDI), $intl->count(self::HINDI));
        }
    }

    public function testT38NumberTokens(): void
    {
        self::assertSame(['100000'], Numbers::digitTokens('1,00,000 people'));
        self::assertSame(['1200'], Numbers::digitTokens('1,200 people'));
        self::assertSame(['1200'], Numbers::digitTokens("1\u{2009}200 people"));
        self::assertSame(['3,5'], Numbers::digitTokens('3,5 Prozent'));
        self::assertSame(['12'], Numbers::digitTokens('१२ लोग'));
        self::assertSame(['12'], Numbers::digitTokens('১২ জন'));
        self::assertSame(['45'], Numbers::digitTokens('٤٥ شخصا'));
        self::assertSame(['6.5'], Numbers::digitTokens('₹6.5%'));
        self::assertTrue(Numbers::isYear('2026'));
        self::assertFalse(Numbers::isYear('2200'));
    }

    public function testDigitValueWithoutIntlUsesRunOffset(): void
    {
        self::assertSame(7, Utf8::digitValue("\u{096D}"));
        self::assertSame(0, Utf8::digitValue("\u{0BE6}"));
    }

    public function testComparisonKey(): void
    {
        self::assertSame(Utf8::comparisonKey('RBI holds rate!'), Utf8::comparisonKey('  rbi HOLDS rate '));
    }
}
