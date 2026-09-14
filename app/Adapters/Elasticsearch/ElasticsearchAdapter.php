<?php

declare(strict_types=1);

namespace App\Adapters\Elasticsearch;

use Throwable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Elastic\Elasticsearch\Client;
use App\Adapters\Contracts\Data\BulkResult;
use App\Adapters\Contracts\Data\HistogramQuery;
use App\Adapters\Contracts\Data\HistogramBucket;
use App\Adapters\Contracts\Data\HistogramResult;
use App\Adapters\Contracts\SearchAdapterInterface;

/**
 * Elasticsearch implementation of the search contract.
 *
 * @see SearchAdapterInterface for the constraints this must honour.
 */
final readonly class ElasticsearchAdapter implements SearchAdapterInterface
{
    public function __construct(
        private Client $client,
        /** @var array<string, mixed> */
        private array $config,
    ) {}

    public function ping(): bool
    {
        try {
            return $this->client->ping()->asBool();
        } catch (Throwable) {
            // A liveness probe that throws is useless — the caller needs a verdict.
            return false;
        }
    }

    public function ensureIndex(): void
    {
        $this->client->indices()->putIndexTemplate([
            'name' => $this->str('template_name'),
            'body' => PostIndexDefinition::template(
                $this->str('index_pattern'),
                $this->str('alias'),
                $this->int('number_of_shards'),
                $this->int('number_of_replicas'),
            ),
        ]);
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $documents
     */
    public function bulkIndex(iterable $documents): BulkResult
    {
        $operations = [];
        $queued = 0;

        foreach ($documents as $document) {
            $publishedAt = Carbon::parse((string) ($document['published_at'] ?? 'now'));

            // Routed to the month index directly rather than through the alias:
            // an alias spanning several indices has no single write target.
            $operations[] = [
                'index' => [
                    '_index' => PostIndexDefinition::indexFor($publishedAt),
                    '_id' => (string) $document['id'],
                ],
            ];
            $operations[] = $document;
            $queued++;
        }

        if ($queued === 0) {
            return new BulkResult(indexed: 0, failed: 0, tookMs: 0);
        }

        $response = $this->client->bulk([
            // The caller refreshes once at the end of an import, not once per chunk —
            // a refresh per chunk is the classic reason bulk indexing crawls.
            'refresh' => 'false',
            'body' => $operations,
        ])->asArray();

        return $this->summariseBulk($response, $queued);
    }

    public function dailyHistogram(HistogramQuery $query): HistogramResult
    {
        $response = $this->client->search([
            'index' => $this->str('alias'),

            // Aggregation only. Documents are never fetched to be counted — at a
            // million posts, paging hits to count them is several orders of
            // magnitude slower than letting Elasticsearch aggregate.
            'body' => [
                'size' => 0,
                'track_total_hits' => true,
                'query' => $this->buildFilter($query),
                'aggs' => [
                    'per_day' => [
                        'date_histogram' => [
                            'field' => 'published_at',
                            'calendar_interval' => (string) config('search.histogram.calendar_interval', 'day'),
                            'time_zone' => $query->timezone,
                            'format' => 'yyyy-MM-dd',
                            // Days with no posts are meaningful in a histogram —
                            // without this the report would silently skip them.
                            'min_doc_count' => 0,
                            'extended_bounds' => [
                                'min' => $query->from->copy()->timezone($query->timezone)->format('Y-m-d'),
                                'max' => $query->to->copy()->timezone($query->timezone)->format('Y-m-d'),
                            ],
                        ],
                    ],
                ],
            ],
        ])->asArray();

        return $this->summariseHistogram($response, $query->timezone);
    }

    public function count(): int
    {
        $response = $this->client->count([
            'index' => $this->str('alias'),
            'ignore_unavailable' => true,
        ])->asArray();

        return (int) ($response['count'] ?? 0);
    }

    /**
     * Keywords and the date range go in `filter`, never in `must`: filter clauses
     * skip scoring entirely and are cacheable, and a histogram has no use for
     * relevance scores.
     *
     * @return array<string, mixed>
     */
    private function buildFilter(HistogramQuery $query): array
    {
        $filters = [[
            'range' => [
                'published_at' => [
                    'gte' => $query->from->toIso8601String(),
                    'lte' => $query->to->toIso8601String(),
                ],
            ],
        ]];

        if ($query->keywords !== []) {
            $clauses = array_map(
                static fn (string $keyword): array => [
                    'multi_match' => [
                        'query' => $keyword,
                        'fields' => PostIndexDefinition::searchFields(),
                        // Persian compounds are frequently written with an
                        // intervening space or ZWNJ; phrase matching keeps
                        // multi-word keywords from matching their words apart.
                        'type' => 'phrase',
                    ],
                ],
                array_values($query->keywords),
            );

            $filters[] = [
                'bool' => $query->matchAllKeywords
                    ? ['must' => $clauses]
                    : ['should' => $clauses, 'minimum_should_match' => 1],
            ];
        }

        if ($query->newsAgencyIds !== []) {
            $filters[] = ['terms' => ['news_agency_id' => array_values($query->newsAgencyIds)]];
        }

        return ['bool' => ['filter' => $filters]];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function summariseBulk(array $response, int $queued): BulkResult
    {
        $errors = [];
        $failed = 0;

        // Bulk reports failures per item with a 200 at the request level, so a
        // caller that only checks the HTTP status would report a clean import
        // while silently dropping documents.
        foreach (Arr::get($response, 'items', []) as $item) {
            $error = Arr::get($item, 'index.error');

            if ($error === null) {
                continue;
            }

            $failed++;

            if (count($errors) < $this->int('max_reported_bulk_errors')) {
                $errors[] = sprintf(
                    '%s: %s',
                    (string) Arr::get($item, 'index._id', 'unknown'),
                    (string) Arr::get($error, 'reason', 'unknown error'),
                );
            }
        }

        return new BulkResult(
            indexed: $queued - $failed,
            failed: $failed,
            tookMs: (int) Arr::get($response, 'took', 0),
            errors: $errors,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function summariseHistogram(array $response, string $timezone): HistogramResult
    {
        $buckets = [];

        foreach (Arr::get($response, 'aggregations.per_day.buckets', []) as $bucket) {
            $buckets[] = new HistogramBucket(
                date: Carbon::parse((string) $bucket['key_as_string'], $timezone),
                count: (int) $bucket['doc_count'],
            );
        }

        return new HistogramResult(
            buckets: $buckets,
            total: (int) Arr::get($response, 'hits.total.value', 0),
            tookMs: (int) Arr::get($response, 'took', 0),
        );
    }

    private function str(string $key): string
    {
        return (string) ($this->config[$key] ?? '');
    }

    private function int(string $key): int
    {
        return (int) ($this->config[$key] ?? 0);
    }
}
