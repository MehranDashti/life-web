<?php

declare(strict_types=1);

namespace App\Services\Search;

use Generator;
use Illuminate\Support\Carbon;

/**
 * Deterministic synthetic Persian posts for the benchmark.
 *
 * A benchmark that cannot be reproduced is an anecdote, so generation is seeded:
 * the same seed always produces byte-identical documents.
 *
 * Keyword selectivity is deliberate and documented. A corpus where every document
 * matches the benchmark keyword — or none do — measures nothing useful, because
 * Elasticsearch's cost depends on how many documents survive the filter. The
 * topic vocabulary below assigns each topic a fixed share of the corpus, so a
 * benchmark filtering on `تهران` selects a known fraction at every corpus size
 * and the resulting curve is comparable across sizes.
 */
final class SyntheticPostFactory
{
    /**
     * Documented per-keyword share of the generated corpus, as a percentage.
     * `تهران` is the benchmark's default filter at ~30% selectivity — high enough
     * that the aggregation does real work, low enough that the filter matters.
     *
     * @var array<string, float>
     */
    public const array SELECTIVITY = [
        'تهران' => 30.0,
        'آلودگی' => 20.0,
        'ترافیک' => 15.0,
        'انرژی' => 12.0,
        'اقتصاد' => 10.0,
        'ورزش' => 8.0,
        'فرهنگ' => 5.0,
    ];

    /**
     * Topic term => share of the corpus that carries it, as a weight.
     * Weights are relative; SELECTIVITY below states the resulting fractions.
     *
     * @var array<string, int>
     */
    private const array TOPICS = [
        'تهران' => 30,
        'آلودگی' => 20,
        'ترافیک' => 15,
        'انرژی' => 12,
        'اقتصاد' => 10,
        'ورزش' => 8,
        'فرهنگ' => 5,
    ];

    /** @var array<int, string> */
    private const array FILLER = [
        'گزارش', 'شهر', 'مردم', 'مسئولان', 'بررسی', 'وضعیت', 'افزایش', 'کاهش',
        'برنامه', 'طرح', 'روز', 'سال', 'منطقه', 'کشور', 'خبر', 'اعلام',
    ];

    /** @var array<int, string> */
    private const array AGENCIES = ['mehr', 'irna', 'isna', 'tasnim', 'fars'];

    /**
     * Documents are spread uniformly across the range so a fixed-width report
     * window selects a predictable fraction of the corpus — which is what makes
     * "query time vs. data volume" interpretable rather than noise.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function generate(
        int $count,
        Carbon $from,
        Carbon $to,
        int $seed = 1,
        int $contentWords = 60,
    ): Generator {
        mt_srand($seed);

        $topics = $this->weightedTopics();
        $spanSeconds = max(1, $to->getTimestamp() - $from->getTimestamp());

        for ($i = 0; $i < $count; $i++) {
            $topic = $topics[mt_rand(0, count($topics) - 1)];
            $publishedAt = $from->copy()->addSeconds((int) round($spanSeconds * $i / max(1, $count - 1)));
            $agency = self::AGENCIES[$i % count(self::AGENCIES)];

            yield [
                'id' => sprintf('synthetic-%d-%d', $seed, $i),
                'title' => $topic.' '.$this->words(6),
                'lead' => $this->words(18),
                'content' => $topic.' '.$this->words($contentWords),
                'published_at' => $publishedAt->toIso8601ZuluString(),
                'url' => "https://example.test/synthetic/{$seed}/{$i}",
                'lf_lang' => 'fa',
                'news_agency_id' => $agency,
                'news_agency_name' => $agency,
                'categories' => [$topic],
                'tags' => [$topic, $agency],
                'main_images' => [],
            ];
        }
    }

    /**
     * Expand the weights into a flat pick-list so selection is a single mt_rand.
     *
     * @return array<int, string>
     */
    private function weightedTopics(): array
    {
        $topics = [];

        foreach (self::TOPICS as $term => $weight) {
            $topics = array_merge($topics, array_fill(0, $weight, $term));
        }

        return $topics;
    }

    private function words(int $count): string
    {
        $words = [];

        for ($i = 0; $i < $count; $i++) {
            $words[] = self::FILLER[mt_rand(0, count(self::FILLER) - 1)];
        }

        return implode(' ', $words);
    }
}
