<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring;

/**
 * Built-in word lists. English only in 0.1.0; other languages are added
 * through filters, keyed by primary language subtag.
 */
final class Lists
{
    /**
     * Stock clickbait phrases, matched case-insensitively on word boundaries
     * after curly quotes are folded to straight ones.
     *
     * @return array<string, list<string>>
     */
    public static function clickbait(): array
    {
        return ['en' => [
            "you won't believe",
            'you will not believe',
            'will blow your mind',
            'blow your mind',
            'what happened next',
            'what happens next',
            'this one trick',
            'one weird trick',
            "here's why",
            'here is why',
            "here's what",
            'everything you need to know',
            'the internet is',
            'goes viral',
            'went viral',
            'shocking',
            'jaw-dropping',
            'mind-blowing',
            'must see',
            'must-see',
            "can't stop",
            'will leave you',
            'is breaking the internet',
            'you need to see',
            'top secret',
            'the truth about',
            'nobody is talking about',
            'number one reason',
            'this is what happens',
            'gone wrong',
            'unbelievable',
            'insane',
        ]];
    }

    /**
     * Written numbers and their digit values.
     *
     * @return array<string, array<string, string>>
     */
    public static function numberWords(): array
    {
        return ['en' => [
            'two' => '2', 'three' => '3', 'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7',
            'eight' => '8', 'nine' => '9', 'ten' => '10', 'eleven' => '11', 'twelve' => '12',
            'thirteen' => '13', 'fourteen' => '14', 'fifteen' => '15', 'sixteen' => '16',
            'seventeen' => '17', 'eighteen' => '18', 'nineteen' => '19', 'twenty' => '20',
            'thirty' => '30', 'forty' => '40', 'fifty' => '50', 'sixty' => '60', 'seventy' => '70',
            'eighty' => '80', 'ninety' => '90', 'hundred' => '100', 'thousand' => '1000',
            'million' => '1000000', 'billion' => '1000000000', 'dozen' => '12',
            'lakh' => '100000', 'crore' => '10000000',
        ]];
    }

    /**
     * Openings that withhold the subject: a bare demonstrative or pronoun.
     * Regular expressions without delimiters, matched at the start, case-insensitively.
     *
     * @return array<string, list<string>>
     */
    public static function withheldSubject(): array
    {
        return ['en' => [
            '(?:this|that|these|those) (?:is|are|was|were|will|could|may|might|one|man|woman|person|thing|things|video|photo|picture|trick|reason)\b',
            '(?:this|that|these|those) (?:is|are) (?:why|how|what|when|who)\b',
            'here(?:\'s| is| are) (?:why|how|what|when|who)\b',
            '(?:he|she|they|it|we) (?:did|does|do|said|says|has|have|had|is|are|was|were|will|just|finally)\b',
            '(?:why|how|what) (?:this|that|these|those|he|she|they|it)\b',
        ]];
    }

    /**
     * Default weights (points possible).
     *
     * @return array<string, int>
     */
    public static function weights(): array
    {
        return [
            'length' => 25,
            'clickbait' => 20,
            'withheld_subject' => 5,
            'numeral_presence' => 5,
            'numeral_support' => 20,
            'entity_support' => 20,
            'shouting' => 5,
            'punctuation' => 5,
            'emoji' => 5,
            'script_match' => 10,
        ];
    }
}
