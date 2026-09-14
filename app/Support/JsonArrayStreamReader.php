<?php

declare(strict_types=1);

namespace App\Support;

use Generator;
use JsonException;
use RuntimeException;

/**
 * Reads a file containing a top-level JSON array and yields one decoded element
 * at a time.
 *
 * `json_decode(file_get_contents($path))` is fine for the 94 KB corpus shipped
 * with this project and useless for the million-document files the benchmark
 * produces — it holds the entire file and the entire decoded structure in memory
 * at once. This scans the byte stream tracking brace/bracket depth, decoding each
 * element as soon as it closes, so peak memory is one element rather than the
 * whole file.
 *
 * Depth tracking is string-aware: braces inside a JSON string, and escaped quotes
 * inside that string, must not change the depth, or a document whose body happens
 * to contain `{` would split in the wrong place.
 */
final readonly class JsonArrayStreamReader
{
    private const int CHUNK_BYTES = 65536;

    public function __construct(private string $path) {}

    /**
     * @return Generator<int, array<string, mixed>>
     *
     * @throws RuntimeException when the file cannot be read or is not a JSON array
     */
    public function elements(): Generator
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open [{$this->path}] for reading.");
        }

        try {
            yield from $this->scan($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return Generator<int, array<string, mixed>>
     */
    private function scan($handle): Generator
    {
        $started = false;      // have we seen the opening '[' yet
        $depth = 0;            // brace/bracket depth inside the current element
        $inString = false;
        $escaped = false;
        $buffer = '';
        $index = 0;

        while (! feof($handle)) {
            $chunk = fread($handle, self::CHUNK_BYTES);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $length = strlen($chunk);

            for ($i = 0; $i < $length; $i++) {
                $char = $chunk[$i];

                if (! $started) {
                    if ($char === '[') {
                        $started = true;

                        continue;
                    }

                    // Only whitespace may precede the opening bracket.
                    if (trim($char) !== '') {
                        throw new RuntimeException("[{$this->path}] is not a JSON array.");
                    }

                    continue;
                }

                if ($depth === 0 && ($char === ',' || trim($char) === '')) {
                    continue;
                }

                if ($depth === 0 && $char === ']') {
                    return;
                }

                $buffer .= $char;

                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($inString) {
                    match ($char) {
                        '\\' => $escaped = true,
                        '"' => $inString = false,
                        default => null,
                    };

                    continue;
                }

                match ($char) {
                    '"' => $inString = true,
                    '{', '[' => $depth++,
                    '}', ']' => $depth--,
                    default => null,
                };

                if ($depth === 0) {
                    yield $index++ => $this->decode($buffer, $index);
                    $buffer = '';
                }
            }
        }

        if (trim($buffer) !== '') {
            throw new RuntimeException("[{$this->path}] ended mid-element; the file is truncated.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json, int $position): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Element #{$position} of [{$this->path}] is not valid JSON: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Element #{$position} of [{$this->path}] is not an object.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
