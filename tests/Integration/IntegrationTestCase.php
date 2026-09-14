<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;
use App\Adapters\Contracts\SearchAdapterInterface;
use App\Adapters\Elasticsearch\ElasticsearchAdapter;

/**
 * Base for the only suite allowed to touch real infrastructure.
 *
 * Excluded from `make test` and from CI; run it deliberately with
 * `make integration`. Skips with a clear message rather than failing when the
 * cluster is not running, so a developer without Docker up is told why.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected ElasticsearchAdapter $search;

    protected function setUp(): void
    {
        parent::setUp();

        // Override the `fake` driver phpunit.xml pins for every other suite.
        config(['search.driver' => 'elasticsearch']);
        $this->app->forgetInstance(SearchAdapterInterface::class);

        /** @var ElasticsearchAdapter $adapter */
        $adapter = $this->app->make(SearchAdapterInterface::class);

        if (! $adapter->ping()) {
            $this->markTestSkipped(
                'Elasticsearch is not reachable at ['
                .implode(', ', (array) config('search.elasticsearch.hosts'))
                .']. Start it with `make up`.',
            );
        }

        $this->search = $adapter;
        $this->search->flush();
        $this->search->ensureIndex();
    }

    protected function tearDown(): void
    {
        if (isset($this->search)) {
            $this->search->flush();
        }

        parent::tearDown();
    }
}
