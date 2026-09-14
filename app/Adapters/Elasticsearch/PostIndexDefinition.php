<?php

declare(strict_types=1);

namespace App\Adapters\Elasticsearch;

use stdClass;
use Illuminate\Support\Carbon;

/**
 * The index template and mapping for the `posts` index family.
 *
 * Two decisions are load-bearing and worth stating explicitly:
 *
 * 1. Indices are time-based — `posts-YYYY.MM` behind the `posts` alias. A report
 *    over a date range touches only the months it covers, so query cost tracks
 *    the range the user asked for rather than the lifetime size of the corpus.
 *    It also makes retention a matter of dropping an index, not deleting by query.
 *
 * 2. Persian text needs normalisation before analysis. Arabic and Persian forms of
 *    the same letter (ي/ی, ك/ک), Eastern Arabic digits, and zero-width non-joiners
 *    all appear in real feeds; without the normalisation filters a search for
 *    "آلودگی" misses documents that spell it with the Arabic yeh.
 */
final class PostIndexDefinition
{
    public const string ANALYZER = 'persian_text';

    /**
     * Concrete index name for a given publication date.
     */
    public static function indexFor(Carbon $publishedAt): string
    {
        return 'posts-'.$publishedAt->format('Y.m');
    }

    /**
     * @return array<string, mixed>
     */
    public static function template(string $indexPattern, string $alias, int $shards, int $replicas): array
    {
        return [
            'index_patterns' => [$indexPattern],
            'priority' => 100,
            'template' => [
                'aliases' => [$alias => new stdClass],
                'settings' => self::settings($shards, $replicas),
                'mappings' => self::mappings(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(int $shards, int $replicas): array
    {
        return [
            'number_of_shards' => $shards,
            'number_of_replicas' => $replicas,

            // The histogram sorts and ranges on published_at constantly; pre-sorting
            // the segments lets Elasticsearch skip whole segments outside the range.
            'sort.field' => 'published_at',
            'sort.order' => 'desc',

            'analysis' => [
                'char_filter' => [
                    'zero_width_spaces' => [
                        'type' => 'mapping',
                        'mappings' => ['\\u200C=>\\u0020'],
                    ],
                ],
                'filter' => [
                    'persian_stop' => [
                        'type' => 'stop',
                        'stopwords' => '_persian_',
                    ],
                ],
                'analyzer' => [
                    self::ANALYZER => [
                        'type' => 'custom',
                        'tokenizer' => 'standard',
                        'char_filter' => ['zero_width_spaces'],
                        'filter' => [
                            'lowercase',
                            'decimal_digit',
                            'arabic_normalization',
                            'persian_normalization',
                            'persian_stop',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * `content` is indexed but not stored in _source retrieval paths we use —
     * the histogram never returns documents, so nothing pays to fetch it.
     *
     * @return array<string, mixed>
     */
    public static function mappings(): array
    {
        return [
            'dynamic' => 'strict',
            'properties' => [
                'id' => ['type' => 'keyword'],
                'title' => ['type' => 'text', 'analyzer' => self::ANALYZER],
                'lead' => ['type' => 'text', 'analyzer' => self::ANALYZER],
                'content' => ['type' => 'text', 'analyzer' => self::ANALYZER],
                'published_at' => ['type' => 'date'],
                'url' => ['type' => 'keyword', 'index' => false],
                'lf_lang' => ['type' => 'keyword'],
                'news_agency_id' => ['type' => 'keyword'],
                'news_agency_name' => ['type' => 'keyword'],
                'categories' => ['type' => 'keyword'],
                'tags' => ['type' => 'keyword'],
                'main_images' => ['type' => 'keyword', 'index' => false],
            ],
        ];
    }

    /**
     * The fields a keyword filter searches, in relevance order. Title and lead are
     * boosted because a keyword in the headline is a stronger topical signal than
     * one buried in the body.
     *
     * @return array<int, string>
     */
    public static function searchFields(): array
    {
        return ['title^3', 'lead^2', 'content'];
    }
}
