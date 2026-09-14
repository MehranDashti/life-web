<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Throwable;
use Illuminate\Console\Command;
use App\Services\Search\PostIndexService;
use App\Adapters\Contracts\SearchAdapterInterface;

class IndexPostsCommand extends Command
{
    protected $signature = 'posts:index
        {file=data.json : Path to a JSON array of posts, relative to the project root}
        {--chunk= : Documents per bulk request (defaults to search.elasticsearch.chunk_size)}
        {--no-refresh : Skip the final refresh; the documents will not be searchable until one happens}';

    protected $description = 'Import a JSON array of posts into Elasticsearch';

    public function handle(PostIndexService $service, SearchAdapterInterface $search): int
    {
        if (! $search->ping()) {
            $this->components->error('Elasticsearch is unreachable; nothing was imported.');

            return self::FAILURE;
        }

        $file = $this->argument('file');
        $path = str_starts_with((string) $file, '/') ? (string) $file : base_path((string) $file);

        $this->components->info("Importing [{$path}]…");

        try {
            $result = $service->importFile(
                path: $path,
                chunkSize: $this->option('chunk') !== null ? (int) $this->option('chunk') : null,
                refresh: ! $this->option('no-refresh'),
                onChunk: function (int $documents, int $chunk): void {
                    $this->components->twoColumnDetail("chunk {$chunk}", "{$documents} documents");
                },
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Indexed', (string) $result->indexed);
        $this->components->twoColumnDetail('Failed', (string) $result->failed);
        $this->components->twoColumnDetail('Chunks', (string) $result->chunks);
        $this->components->twoColumnDetail('Duration', "{$result->durationMs} ms");
        $this->components->twoColumnDetail('Rate', "{$result->documentsPerSecond()} docs/s");
        $this->components->twoColumnDetail('Total in index', (string) $search->count());

        // Bulk reports per-item failures inside an otherwise successful response, so
        // a caller that only checked the HTTP status would report a clean import
        // while silently dropping documents.
        if ($result->hasFailures()) {
            $this->newLine();
            $this->components->error("{$result->failed} document(s) were rejected:");

            foreach ($result->errors as $error) {
                $this->line("  • {$error}");
            }

            return self::FAILURE;
        }

        $this->components->info('Import complete.');

        return self::SUCCESS;
    }
}
