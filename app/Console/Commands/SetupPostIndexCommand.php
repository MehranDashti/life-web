<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Throwable;
use Illuminate\Console\Command;
use App\Services\Search\PostIndexService;
use App\Adapters\Contracts\SearchAdapterInterface;

class SetupPostIndexCommand extends Command
{
    protected $signature = 'posts:setup';

    protected $description = 'Register the posts index template and alias in Elasticsearch (idempotent)';

    public function handle(PostIndexService $service, SearchAdapterInterface $search): int
    {
        if (! $search->ping()) {
            $this->components->error(
                'Elasticsearch is unreachable at ['.implode(', ', (array) config('search.elasticsearch.hosts')).'].',
            );

            return self::FAILURE;
        }

        try {
            $service->setup();
        } catch (Throwable $exception) {
            $this->components->error('Failed to register the index template: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Index template registered.');
        $this->components->twoColumnDetail('Template', (string) config('search.elasticsearch.template_name'));
        $this->components->twoColumnDetail('Index pattern', (string) config('search.elasticsearch.index_pattern'));
        $this->components->twoColumnDetail('Read alias', (string) config('search.elasticsearch.alias'));
        $this->components->twoColumnDetail('Shards / replicas', sprintf(
            '%d / %d',
            (int) config('search.elasticsearch.number_of_shards'),
            (int) config('search.elasticsearch.number_of_replicas'),
        ));
        $this->components->twoColumnDetail('Documents indexed', (string) $search->count());

        return self::SUCCESS;
    }
}
