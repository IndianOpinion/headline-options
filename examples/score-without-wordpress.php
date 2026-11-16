<?php

/**
 * Score headlines with no WordPress and no model call.
 *
 * Run: php examples/score-without-wordpress.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use IndianOpinion\HeadlineOptions\Scoring\ScorerFactory;
use IndianOpinion\HeadlineOptions\Settings;

$article = 'The Reserve Bank of India kept the repo rate at 6.5% on Wednesday. Governor Sanjay Malhotra said '
    . 'inflation had eased to 4.2% in October and that the bank would watch food prices closely.';

$factory = new ScorerFactory(new Settings());
$scorer = $factory->create('en_GB');
$facts = $factory->article($article, 'en_GB');

$headlines = [
    'Reserve Bank holds repo rate at 6.5% as inflation eases',
    "You won't believe what the Reserve Bank did next!!",
    '12 dead as Modi visits Mumbai',
];

foreach ($headlines as $headline) {
    $score = $scorer->score($headline, $facts, 'Repo rate unchanged');
    printf("%3s  %-5s  %s\n", $score->total ?? '-', $score->band ?? '-', $score->headline);
    foreach ($score->results as $rule) {
        if ($rule->status->value !== 'pass') {
            printf("            %-16s %-4s %s\n", $rule->ruleId, $rule->status->value, $rule->reason);
        }
    }
}
