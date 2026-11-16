<?php

declare(strict_types=1);

namespace IndianOpinion\HeadlineOptions\Generation;

/**
 * The output schema of PRD 5.6, plus `entities`: the names (people, places,
 * organisations) the model says its candidates use, as written in the
 * article. The entity rule checks each one that appears in a headline
 * against the article (ruling 7, for scripts without case). A prompt
 * template can never change the schema.
 */
final class OutputSchema
{
    public const NAME = 'headline_candidates';

    /** @return array<string, mixed> */
    public static function toArray(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'candidates' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 10,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'headline' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                            'angle' => ['type' => 'string', 'maxLength' => 80],
                        ],
                        'required' => ['headline', 'angle'],
                        'additionalProperties' => false,
                    ],
                ],
                'entities' => [
                    'type' => 'array',
                    'maxItems' => 30,
                    'items' => ['type' => 'string', 'maxLength' => 80],
                ],
            ],
            'required' => ['candidates', 'entities'],
            'additionalProperties' => false,
        ];
    }
}
