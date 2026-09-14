<?php

declare(strict_types=1);

namespace Tests\Unit\Adapters;

use Throwable;
use Tests\TestCase;
use Illuminate\Support\Carbon;
use Elastic\Elasticsearch\ClientBuilder;
use App\Exceptions\SearchUnavailableException;
use App\Adapters\Contracts\Data\HistogramQuery;
use App\Adapters\Elasticsearch\ElasticsearchAdapter;

/**
 * Exercises the real adapter against a deliberately unreachable host. No mocking:
 * the engine client is final and a stub would prove nothing about how the real
 * transport's exceptions are classified.
 *
 * The behaviour under test is the translation boundary. Controllers catch
 * Throwable and shape the response themselves, so the render closure in
 * bootstrap/app.php never sees a driver exception raised inside one — the correct
 * status has to travel with the exception instead.
 */
class ElasticsearchAdapterTest extends TestCase
{
    private ElasticsearchAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        // Port 1 is reserved and never listening, so every call fails at connect.
        $client = ClientBuilder::create()
            ->setHosts(['http://127.0.0.1:1'])
            ->setRetries(0)
            ->build();

        $this->adapter = new ElasticsearchAdapter($client, [
            'alias' => 'posts',
            'index_pattern' => 'posts-*',
            'template_name' => 'posts-template',
            'number_of_shards' => 1,
            'number_of_replicas' => 0,
            'max_reported_bulk_errors' => 10,
        ]);
    }

    /**
     * A liveness probe that throws is useless — the health endpoint needs a verdict.
     */
    public function test_ping_returns_false_instead_of_throwing(): void
    {
        $this->assertFalse($this->adapter->ping());
    }

    public function test_a_histogram_against_an_unreachable_cluster_becomes_a_search_failure(): void
    {
        $this->expectException(SearchUnavailableException::class);

        $this->adapter->dailyHistogram(new HistogramQuery(
            keywords: ['تهران'],
            from: Carbon::parse('2024-12-18T00:00:00Z'),
            to: Carbon::parse('2024-12-21T23:59:59Z'),
        ));
    }

    public function test_the_translated_failure_carries_a_503(): void
    {
        try {
            $this->adapter->count();
            $this->fail('An unreachable cluster must raise.');
        } catch (SearchUnavailableException $exception) {
            $this->assertSame(503, $exception->getCode());
        }
    }

    /**
     * The driver's message must not reach the caller, but it must reach the logs.
     */
    public function test_the_original_driver_exception_is_preserved_as_the_previous(): void
    {
        try {
            $this->adapter->count();
            $this->fail('An unreachable cluster must raise.');
        } catch (SearchUnavailableException $exception) {
            $this->assertNotNull($exception->getPrevious());
            $this->assertStringNotContainsString('127.0.0.1', $exception->getMessage());
        }
    }

    /**
     * Operator commands are the exception: `posts:index` printing "the search
     * service is unavailable" instead of the real connect error would make an
     * import failure undiagnosable.
     */
    public function test_operator_operations_surface_the_real_driver_error(): void
    {
        try {
            $this->adapter->ensureIndex();
            $this->fail('An unreachable cluster must raise.');
        } catch (Throwable $exception) {
            $this->assertNotInstanceOf(SearchUnavailableException::class, $exception);
        }
    }
}
