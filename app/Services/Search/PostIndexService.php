<?php

declare(strict_types=1);

namespace App\Services\Search;

use Closure;
use Throwable;
use RuntimeException;
use Illuminate\Support\Carbon;
use App\Support\JsonArrayStreamReader;
use App\Services\Search\Data\ImportResult;
use App\Adapters\Contracts\SearchAdapterInterface;

/**
 * The operational layer over the search adapter: create the index family, get
 * documents into it, and get them back out again for the benchmark.
 *
 * All engine access still goes through SearchAdapterInterface — this service adds
 * chunking, retry and reporting, not query construction.
 */
final readonly class PostIndexService
{
    /**
     * Bulk rejection (429) means the cluster's write queue is full, not that the
     * documents are bad. Retrying with backoff is the difference between a slow
     * import and a lossy one.
     */
    private const int MAX_CHUNK_ATTEMPTS = 4;

    public function __construct(private SearchAdapterInterface $search) {}

    /**
     * Register the index template. Idempotent.
     */
    public function setup(): void
    {
        $this->search->ensureIndex();
    }

    public function importFile(string $path, ?int $chunkSize = null, bool $refresh = true, ?Closure $onChunk = null): ImportResult
    {
        if (! is_file($path)) {
            throw new RuntimeException("Corpus file [{$path}] does not exist.");
        }

        return $this->import(
            (new JsonArrayStreamReader($path))->elements(),
            $chunkSize,
            $refresh,
            $onChunk,
        );
    }

    public function generateSynthetic(
        int $count,
        Carbon $from,
        Carbon $to,
        int $seed = 1,
        int $contentWords = 60,
        ?int $chunkSize = null,
        bool $refresh = true,
        ?Closure $onChunk = null,
    ): ImportResult {
        return $this->import(
            (new SyntheticPostFactory)->generate($count, $from, $to, $seed, $contentWords),
            $chunkSize,
            $refresh,
            $onChunk,
        );
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $documents
     * @param  Closure(int, int): void|null  $onChunk  receives (documentsInChunk, chunkNumber)
     */
    public function import(iterable $documents, ?int $chunkSize = null, bool $refresh = true, ?Closure $onChunk = null): ImportResult
    {
        $this->setup();

        $chunkSize ??= (int) config('search.elasticsearch.chunk_size', 1000);
        $maxErrors = (int) config('search.elasticsearch.max_reported_bulk_errors', 10);
        $startedAt = microtime(true);

        $indexed = 0;
        $failed = 0;
        $chunks = 0;
        $errors = [];
        $buffer = [];

        foreach ($documents as $document) {
            $buffer[] = $document;

            if (count($buffer) < $chunkSize) {
                continue;
            }

            $this->flushChunk($buffer, $indexed, $failed, $chunks, $errors, $maxErrors, $onChunk);
            $buffer = [];
        }

        if ($buffer !== []) {
            $this->flushChunk($buffer, $indexed, $failed, $chunks, $errors, $maxErrors, $onChunk);
        }

        // One refresh at the end, never one per chunk — a refresh per chunk is the
        // standard reason a bulk import runs an order of magnitude slower than it should.
        if ($refresh) {
            $this->search->refresh();
        }

        return new ImportResult(
            indexed: $indexed,
            failed: $failed,
            chunks: $chunks,
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            errors: $errors,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunk
     * @param  array<int, string>  $errors
     * @param  Closure(int, int): void|null  $onChunk
     */
    private function flushChunk(
        array $chunk,
        int &$indexed,
        int &$failed,
        int &$chunks,
        array &$errors,
        int $maxErrors,
        ?Closure $onChunk,
    ): void {
        $chunks++;
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $result = $this->search->bulkIndex($chunk);
                break;
            } catch (Throwable $exception) {
                if ($attempt >= self::MAX_CHUNK_ATTEMPTS) {
                    throw $exception;
                }

                // Exponential backoff: 0.5s, 1s, 2s.
                usleep(500_000 * 2 ** ($attempt - 1));
            }
        }

        $indexed += $result->indexed;
        $failed += $result->failed;

        foreach ($result->errors as $error) {
            if (count($errors) < $maxErrors) {
                $errors[] = $error;
            }
        }

        // __invoke, never Closure::call(): rebinding $this to this service breaks
        // any callback that legitimately closes over its own object (a command
        // reaching for $this->components, for instance).
        $onChunk?->__invoke(count($chunk), $chunks);
    }
}
