<?php

declare(strict_types=1);

namespace Database\Seeders;

use Throwable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use App\Services\Search\PostIndexService;
use App\Adapters\Contracts\SearchAdapterInterface;

/**
 * Fills Elasticsearch with the supplied corpus so a fresh install has something
 * to report on.
 *
 * Elasticsearch is a soft dependency: it backs reporting, not the core API. A
 * seeder that hard-failed when the cluster was unreachable would make
 * `make fresh` impossible to run without the full stack, so an outage is logged
 * and skipped rather than thrown.
 *
 * Idempotent — documents are indexed under their source `id`, so re-running
 * overwrites rather than duplicating.
 */
class PostSeeder extends Seeder
{
    public const CORPUS = 'database/seeders/data/data.json';

    public function run(): void
    {
        $search = app(SearchAdapterInterface::class);

        if (! $search->ping()) {
            $this->report('Elasticsearch is unreachable; skipping the post corpus. Run `php artisan posts:index` once it is up.');

            return;
        }

        $path = base_path(self::CORPUS);

        if (! is_file($path)) {
            $this->report('['.self::CORPUS.'] not found; skipping the post corpus.');

            return;
        }

        try {
            $result = app(PostIndexService::class)->importFile($path);
        } catch (Throwable $exception) {
            $this->report('Post corpus import failed: '.$exception->getMessage());

            return;
        }

        $this->report(sprintf(
            'Indexed %d posts from %s in %dms (%d failed). Index now holds %d documents.',
            $result->indexed,
            self::CORPUS,
            $result->durationMs,
            $result->failed,
            $search->count(),
        ));
    }

    /**
     * Logged rather than printed: this seeder also runs from the container
     * entrypoint, where there is no console to write to, and the log is where a
     * fresh deploy is diagnosed from anyway.
     */
    private function report(string $message): void
    {
        Log::info($message);
    }
}
