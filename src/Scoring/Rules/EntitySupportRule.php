<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Scoring\Rules;

use IndianOpinion\HeadlineOptions\Scoring\Article;
use IndianOpinion\HeadlineOptions\Scoring\Headline;
use IndianOpinion\HeadlineOptions\Scoring\RuleResult;
use IndianOpinion\HeadlineOptions\Scoring\Status;
use IndianOpinion\HeadlineOptions\Text\Utf8;

/**
 * Cases 41 and 42: every name in the headline must appear in the article.
 *
 * Names are found by plain rules, not by a model: capitalised runs not at the
 * start, acronyms, tag and category names, and known entities. In scripts
 * without case only tags, categories, Latin tokens and known entities are
 * used; when none is found the rule is `na`.
 */
final class EntitySupportRule extends AbstractRule
{
    public function id(): string
    {
        return 'entity_support';
    }

    public function evaluate(Headline $headline, Article $article): RuleResult
    {
        $entities = $this->entities($headline, $article);
        if ($entities === []) {
            return $this->na($headline->caseless
                ? 'No name found by tags, Latin words or known entities (scripts without case).'
                : 'No name found in the headline.');
        }
        $missing = [];
        foreach ($entities as $entity) {
            if (!$this->supported($entity, $article)) {
                $missing[] = $entity;
            }
        }
        if ($missing === []) {
            return $this->result(Status::Pass, 'Every name appears in the article: ' . implode(', ', $entities) . '.', ['entities' => $entities]);
        }

        return $this->result(Status::Fail, 'Not in the article: ' . implode(', ', $missing) . '.', ['missing' => $missing]);
    }

    /**
     * @return list<string>
     */
    public function entities(Headline $headline, Article $article): array
    {
        if ($headline->caseless) {
            $found = $headline->latinWords();
        } elseif (self::mostlyCapitals($headline->text)) {
            // Capitalisation says nothing in an all-caps line; the shouting rule handles it.
            $found = [];
        } else {
            $found = $headline->capitalisedEntities();
        }
        $headlineFolded = Utf8::fold(Utf8::foldQuotes($headline->text));
        $terms = array_merge($article->tags, $article->categories, $article->knownEntities);
        foreach ($terms as $term) {
            $term = trim($term);
            if ($term === '') {
                continue;
            }
            $needle = Utf8::fold(Utf8::foldQuotes($term));
            $pattern = '/(?<![\p{L}\p{M}\p{Nd}])' . preg_quote($needle, '/') . '(?![\p{L}\p{M}\p{Nd}])/u';
            if (preg_match($pattern, $headlineFolded) === 1) {
                $found[] = $term;
            }
        }
        // Drop entries covered by a longer one, keep first-seen order.
        $out = [];
        foreach ($found as $entity) {
            $key = Utf8::fold($entity);
            $dupe = false;
            foreach ($out as $existing) {
                if (Utf8::fold($existing) === $key) {
                    $dupe = true;
                    break;
                }
            }
            if (!$dupe) {
                $out[] = $entity;
            }
        }

        return $out;
    }

    private static function mostlyCapitals(string $text): bool
    {
        $upper = preg_match_all('/[\p{Lu}\p{Lt}]/u', $text);
        $lower = preg_match_all('/\p{Ll}/u', $text);

        return is_int($upper) && is_int($lower) && $upper > $lower && $upper + $lower >= 8;
    }

    private function supported(string $entity, Article $article): bool
    {
        if ($this->formMatches($entity, $article)) {
            return true;
        }
        // A capitalised run ("Reserve Bank Governor") is supported when each word is.
        $words = Utf8::words($entity);
        if (count($words) < 2) {
            return false;
        }
        foreach ($words as $word) {
            if (!$this->formMatches($word, $article)) {
                return false;
            }
        }

        return true;
    }

    private function formMatches(string $entity, Article $article): bool
    {
        foreach (self::forms($entity) as $form) {
            if ($article->containsPhrase($form)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The entity, then with a possessive or plural stripped.
     *
     * @return list<string>
     */
    public static function forms(string $entity): array
    {
        $entity = Utf8::foldQuotes(trim($entity));
        $forms = [$entity];
        if (preg_match("/^(.+)'s$/iu", $entity, $m) === 1) {
            $forms[] = $m[1];
        } elseif (preg_match("/^(.+s)'$/iu", $entity, $m) === 1) {
            $forms[] = $m[1];
        }
        $last = end($forms);
        if (preg_match('/^(.+\p{L})s$/iu', $last, $m) === 1) {
            $forms[] = $m[1];
        }

        return array_values(array_unique($forms));
    }
}
