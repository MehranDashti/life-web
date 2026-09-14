<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Throwable;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use App\Adapters\Contracts\SearchAdapterInterface;

/**
 * Destructive. Exists for the benchmark sweep, which measures the same query
 * against successive corpus sizes and therefore needs a clean index between them.
 */
class FlushPostIndexCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'posts:flush {--force : Run without confirmation}';

    protected $description = 'Delete every posts index (destructive)';

    public function handle(SearchAdapterInterface $search): int
    {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        try {
            $before = $search->count();
            $search->flush();
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Dropped the posts index family ({$before} documents).");

        return self::SUCCESS;
    }
}
