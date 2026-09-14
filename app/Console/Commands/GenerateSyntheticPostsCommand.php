<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Throwable;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use App\Services\Search\PostIndexService;
use App\Services\Search\SyntheticPostFactory;
use App\Adapters\Contracts\SearchAdapterInterface;

class GenerateSyntheticPostsCommand extends Command
{
    protected $signature = 'posts:synthetic
        {count : How many documents to generate}
        {--from=2024-01-01 : Start of the publication range}
        {--to=2024-12-31 : End of the publication range}
        {--seed=1 : Generation seed; the same seed always produces identical documents}
        {--content-words=60 : Words of body text per document}
        {--chunk= : Documents per bulk request}';

    protected $description = 'Generate a deterministic synthetic corpus for benchmarking';

    public function handle(PostIndexService $service, SearchAdapterInterface $search): int
    {
        if (! $search->ping()) {
            $this->components->error('Elasticsearch is unreachable; nothing was generated.');

            return self::FAILURE;
        }

        $count = (int) $this->argument('count');

        if ($count < 1) {
            $this->components->error('count must be at least 1.');

            return self::FAILURE;
        }

        $from = Carbon::parse((string) $this->option('from'))->startOfDay();
        $to = Carbon::parse((string) $this->option('to'))->endOfDay();

        if ($to->lte($from)) {
            $this->components->error('--to must be after --from.');

            return self::FAILURE;
        }

        $this->components->info("Generating {$count} synthetic posts…");
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        try {
            $result = $service->generateSynthetic(
                count: $count,
                from: $from,
                to: $to,
                seed: (int) $this->option('seed'),
                contentWords: (int) $this->option('content-words'),
                chunkSize: $this->option('chunk') !== null ? (int) $this->option('chunk') : null,
                onChunk: function (int $documents) use ($bar): void {
                    $bar->advance($documents);
                },
            );
        } catch (Throwable $exception) {
            $bar->finish();
            $this->newLine(2);
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine(2);

        $this->components->twoColumnDetail('Indexed', (string) $result->indexed);
        $this->components->twoColumnDetail('Failed', (string) $result->failed);
        $this->components->twoColumnDetail('Duration', "{$result->durationMs} ms");
        $this->components->twoColumnDetail('Rate', "{$result->documentsPerSecond()} docs/s");
        $this->components->twoColumnDetail('Total in index', (string) $search->count());

        $this->newLine();
        $this->line('  Keyword selectivity in the generated corpus:');

        foreach (SyntheticPostFactory::SELECTIVITY as $keyword => $percentage) {
            $this->components->twoColumnDetail("  {$keyword}", "~{$percentage}%");
        }

        return $result->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
