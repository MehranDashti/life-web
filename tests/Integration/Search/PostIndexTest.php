<?php

declare(strict_types=1);

namespace Tests\Integration\Search;

use Throwable;
use Illuminate\Support\Carbon;
use App\Services\Search\PostIndexService;
use Tests\Integration\IntegrationTestCase;
use App\Adapters\Contracts\Data\HistogramQuery;
use App\Adapters\Contracts\Data\HistogramBucket;
use App\Adapters\Elasticsearch\PostIndexDefinition;

/**
 * Exercises the real engine. Everything here depends on behaviour the in-memory
 * double cannot reproduce: the analysis chain, the strict mapping, and the shape
 * of a real date_histogram response.
 */
class PostIndexTest extends IntegrationTestCase
{
    public function test_the_template_creates_monthly_indices_behind_the_read_alias(): void
    {
        $this->importCorpus();

        $indices = $this->search->concreteIndices();

        $this->assertContains('posts-2024.12', $indices);
        $this->assertSame(21, $this->search->count(), 'The alias must resolve to the monthly index.');
    }

    public function test_importing_the_same_corpus_twice_does_not_duplicate_documents(): void
    {
        $this->importCorpus();
        $this->importCorpus();

        $this->assertSame(21, $this->search->count());
    }

    /**
     * Hand-computed against data.json, which spans 2024-12-18 to 2024-12-21.
     * A histogram with holes misrepresents the data, so the days between must be
     * present as explicit zeros.
     */
    public function test_the_histogram_returns_a_bucket_for_every_day_including_empty_ones(): void
    {
        $this->importCorpus();

        $result = $this->search->dailyHistogram(new HistogramQuery(
            keywords: ['تهران'],
            from: Carbon::parse('2024-12-15T00:00:00Z'),
            to: Carbon::parse('2024-12-21T23:59:59Z'),
            timezone: 'UTC',
        ));

        $this->assertCount(7, $result->buckets);
        $this->assertSame(
            ['2024-12-15', '2024-12-16', '2024-12-17'],
            array_map(
                static fn (HistogramBucket $b): string => $b->date->format('Y-m-d'),
                array_slice($result->buckets, 0, 3),
            ),
        );
        $this->assertSame([0, 0, 0], array_map(
            static fn (HistogramBucket $b): int => $b->count,
            array_slice($result->buckets, 0, 3),
        ));
        $this->assertGreaterThan(0, $result->total);
        $this->assertSame($result->total, array_sum(array_map(
            static fn (HistogramBucket $b): int => $b->count,
            $result->buckets,
        )));
    }

    /**
     * Persian and Arabic spellings of the same letter must converge on one token,
     * or a keyword search silently misses documents from half the feeds.
     */
    public function test_arabic_and_persian_spellings_match_the_same_documents(): void
    {
        $this->importCorpus();

        $persianYeh = $this->histogramTotal('ایران');   // U+06CC
        $arabicYeh = $this->histogramTotal('ايران');    // U+064A

        $this->assertGreaterThan(0, $persianYeh, 'The corpus should contain the word at all.');
        $this->assertSame($persianYeh, $arabicYeh);
    }

    public function test_eastern_arabic_digits_are_normalised(): void
    {
        $this->search->bulkIndex([[
            'id' => 'digits',
            'title' => 'گزارش سال ۱۴۰۳',
            'lead' => '',
            'content' => '',
            'published_at' => '2024-12-20T00:00:00Z',
            'url' => 'https://example.test/digits',
            'lf_lang' => 'fa',
            'news_agency_id' => 'test',
            'news_agency_name' => 'test',
            'categories' => [],
            'tags' => [],
            'main_images' => [],
        ]]);
        $this->search->refresh();

        $this->assertSame(1, $this->histogramTotal('1403'));
    }

    /**
     * Strict mapping: a feed whose shape drifts must fail loudly rather than
     * silently creating an unanalysed field.
     */
    public function test_a_document_with_an_unmapped_field_is_rejected(): void
    {
        $result = $this->search->bulkIndex([[
            'id' => 'strict',
            'title' => 'x',
            'published_at' => '2024-12-20T00:00:00Z',
            'unexpected_field' => 'boom',
        ]]);

        $this->assertTrue($result->hasFailures());
        $this->assertSame(0, $result->indexed);
        $this->assertStringContainsString('strict', implode(' ', $result->errors));
    }

    public function test_the_search_fields_cover_title_lead_and_content(): void
    {
        $this->search->bulkIndex([
            $this->makePost('in-title', title: 'کلیدواژه یکتا الف'),
            $this->makePost('in-lead', lead: 'کلیدواژه یکتا الف'),
            $this->makePost('in-content', content: 'کلیدواژه یکتا الف'),
        ]);
        $this->search->refresh();

        $this->assertSame(3, $this->histogramTotal('کلیدواژه یکتا الف'));
        $this->assertContains('title^3', PostIndexDefinition::searchFields());
    }

    public function test_the_adapter_never_throws_on_ping_when_the_cluster_is_reachable(): void
    {
        try {
            $this->assertTrue($this->search->ping());
        } catch (Throwable $exception) {
            $this->fail('ping() must return a verdict, never throw: '.$exception->getMessage());
        }
    }

    private function importCorpus(): void
    {
        $this->app->make(PostIndexService::class)->importFile(base_path('data.json'));
    }

    private function histogramTotal(string $keyword): int
    {
        return $this->search->dailyHistogram(new HistogramQuery(
            keywords: [$keyword],
            from: Carbon::parse('2024-01-01T00:00:00Z'),
            to: Carbon::parse('2025-01-01T00:00:00Z'),
            timezone: 'UTC',
        ))->total;
    }

    /**
     * @return array<string, mixed>
     */
    private function makePost(string $id, string $title = 'عنوان', string $lead = '', string $content = ''): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'lead' => $lead,
            'content' => $content,
            'published_at' => '2024-12-20T00:00:00Z',
            'url' => "https://example.test/{$id}",
            'lf_lang' => 'fa',
            'news_agency_id' => 'test',
            'news_agency_name' => 'test',
            'categories' => [],
            'tags' => [],
            'main_images' => [],
        ];
    }
}
