<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use Tests\TestCase;
use Illuminate\Support\Carbon;
use App\Services\Search\SyntheticPostFactory;
use App\Adapters\Elasticsearch\PostIndexDefinition;

/**
 * The benchmark's credibility rests on this class. If generation is not
 * reproducible the numbers are anecdotes, and if keyword selectivity drifts the
 * "query time vs. corpus size" curve measures the filter rather than the corpus.
 */
class SyntheticPostFactoryTest extends TestCase
{
    private SyntheticPostFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new SyntheticPostFactory;
    }

    public function test_it_generates_the_requested_number_of_documents(): void
    {
        $this->assertCount(250, $this->generate(250));
    }

    public function test_the_same_seed_produces_identical_documents(): void
    {
        $this->assertEquals($this->generate(50, seed: 7), $this->generate(50, seed: 7));
    }

    public function test_a_different_seed_produces_different_documents(): void
    {
        $this->assertNotEquals($this->generate(50, seed: 7), $this->generate(50, seed: 8));
    }

    public function test_document_ids_are_unique_so_indexing_cannot_silently_overwrite(): void
    {
        $ids = array_column($this->generate(500), 'id');

        $this->assertCount(500, array_unique($ids));
    }

    public function test_ids_are_namespaced_by_seed_so_two_corpora_do_not_collide(): void
    {
        $first = array_column($this->generate(10, seed: 1), 'id');
        $second = array_column($this->generate(10, seed: 2), 'id');

        $this->assertSame([], array_intersect($first, $second));
    }

    /**
     * Every field must exist in the index mapping, which is `dynamic: strict` —
     * an extra field would make the whole benchmark fail to index.
     */
    public function test_every_generated_field_exists_in_the_strict_mapping(): void
    {
        $mapped = array_keys(PostIndexDefinition::mappings()['properties']);
        $generated = array_keys($this->generate(1)[0]);

        $this->assertSame([], array_diff($generated, $mapped), 'A generated field is not in the mapping.');
    }

    public function test_documents_are_published_inside_the_requested_range(): void
    {
        $from = Carbon::parse('2024-03-01T00:00:00Z');
        $to = Carbon::parse('2024-04-30T23:59:59Z');

        foreach ($this->factory->generate(100, $from, $to) as $document) {
            $publishedAt = Carbon::parse((string) $document['published_at']);

            $this->assertTrue($publishedAt->gte($from));
            $this->assertTrue($publishedAt->lte($to));
        }
    }

    /**
     * Uniform spread is what makes a fixed-width report window select the same
     * fraction of the corpus at every size, which is what makes the benchmark
     * curve interpretable.
     */
    public function test_documents_are_spread_uniformly_across_the_range(): void
    {
        $from = Carbon::parse('2024-01-01T00:00:00Z');
        $to = Carbon::parse('2024-12-31T23:59:59Z');

        $months = [];

        foreach ($this->factory->generate(1200, $from, $to) as $document) {
            $month = Carbon::parse((string) $document['published_at'])->format('m');
            $months[$month] = ($months[$month] ?? 0) + 1;
        }

        $this->assertCount(12, $months, 'Every month in the range should receive documents.');

        // 1200 documents over 12 months is 100 each; allow generous slack for
        // month-length differences without letting a real skew pass.
        foreach ($months as $month => $count) {
            $this->assertGreaterThan(60, $count, "Month {$month} is under-represented.");
            $this->assertLessThan(140, $count, "Month {$month} is over-represented.");
        }
    }

    /**
     * The documented selectivity is quoted in the README and drives the benchmark's
     * keyword choice; if generation drifts from it the published numbers become wrong.
     */
    public function test_keyword_selectivity_matches_the_documented_percentages(): void
    {
        $documents = $this->generate(4000);

        foreach (SyntheticPostFactory::SELECTIVITY as $keyword => $expected) {
            $matches = 0;

            foreach ($documents as $document) {
                if (str_contains((string) $document['title'], (string) $keyword)) {
                    $matches++;
                }
            }

            $actual = $matches / count($documents) * 100;

            $this->assertEqualsWithDelta(
                $expected,
                $actual,
                3.0,
                "Keyword [{$keyword}] should match ~{$expected}% of the corpus, got ".round($actual, 1).'%.',
            );
        }
    }

    public function test_the_documented_percentages_sum_to_the_whole_corpus(): void
    {
        $this->assertEqualsWithDelta(100.0, array_sum(SyntheticPostFactory::SELECTIVITY), 0.01);
    }

    public function test_the_topic_appears_in_both_the_title_and_the_body(): void
    {
        $document = $this->generate(1)[0];

        $this->assertNotSame('', (string) $document['title']);
        $this->assertNotSame('', (string) $document['content']);

        // The topic leads the title, is the document's category, and recurs in the
        // body — so a keyword filter matches across all three search fields.
        $topic = explode(' ', (string) $document['title'])[0];

        $this->assertSame($topic, $document['categories'][0]);
        $this->assertStringContainsString($topic, (string) $document['content']);
        $this->assertArrayHasKey($topic, SyntheticPostFactory::SELECTIVITY);
    }

    public function test_body_length_is_controllable_for_the_benchmark(): void
    {
        $short = $this->generate(1, contentWords: 10)[0];
        $long = $this->generate(1, contentWords: 200)[0];

        $this->assertLessThan(
            strlen((string) $long['content']),
            strlen((string) $short['content']),
        );
    }

    public function test_published_at_is_written_in_the_zulu_format_the_mapping_expects(): void
    {
        $document = $this->generate(1)[0];

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            (string) $document['published_at'],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generate(int $count, int $seed = 1, int $contentWords = 20): array
    {
        return iterator_to_array($this->factory->generate(
            $count,
            Carbon::parse('2024-01-01T00:00:00Z'),
            Carbon::parse('2024-12-31T23:59:59Z'),
            $seed,
            $contentWords,
        ));
    }
}
