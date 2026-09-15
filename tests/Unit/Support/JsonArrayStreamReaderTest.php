<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Tests\TestCase;
use RuntimeException;
use App\Support\JsonArrayStreamReader;

class JsonArrayStreamReaderTest extends TestCase
{
    /** @var array<int, string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_it_yields_every_element_of_a_json_array(): void
    {
        $path = $this->write('[{"id":"a"},{"id":"b"},{"id":"c"}]');

        $ids = array_column(iterator_to_array((new JsonArrayStreamReader($path))->elements()), 'id');

        $this->assertSame(['a', 'b', 'c'], $ids);
    }

    public function test_it_handles_nested_objects_and_arrays(): void
    {
        $path = $this->write('[{"id":"a","tags":["x","y"],"meta":{"deep":{"deeper":1}}},{"id":"b"}]');

        $elements = iterator_to_array((new JsonArrayStreamReader($path))->elements());

        $this->assertCount(2, $elements);
        $this->assertSame(1, $elements[0]['meta']['deep']['deeper']);
        $this->assertSame(['x', 'y'], $elements[0]['tags']);
    }

    /**
     * A brace inside a string must not change depth, or a post whose body happens
     * to contain `{` would split in the wrong place and corrupt the import.
     */
    public function test_braces_inside_strings_do_not_split_elements(): void
    {
        $path = $this->write('[{"id":"a","content":"a } b { c ] d ["},{"id":"b"}]');

        $elements = iterator_to_array((new JsonArrayStreamReader($path))->elements());

        $this->assertCount(2, $elements);
        $this->assertSame('a } b { c ] d [', $elements[0]['content']);
    }

    public function test_escaped_quotes_inside_strings_are_respected(): void
    {
        $path = $this->write('[{"id":"a","content":"he said \\"hi\\" and {"},{"id":"b"}]');

        $elements = iterator_to_array((new JsonArrayStreamReader($path))->elements());

        $this->assertCount(2, $elements);
        $this->assertSame('he said "hi" and {', $elements[0]['content']);
    }

    public function test_whitespace_and_newlines_between_elements_are_ignored(): void
    {
        $path = $this->write("[\n  {\"id\":\"a\"},\n\n  {\"id\":\"b\"}\n]\n");

        $this->assertCount(2, iterator_to_array((new JsonArrayStreamReader($path))->elements()));
    }

    public function test_an_empty_array_yields_nothing(): void
    {
        $path = $this->write('[]');

        $this->assertSame([], iterator_to_array((new JsonArrayStreamReader($path))->elements()));
    }

    public function test_a_truncated_file_is_reported_rather_than_silently_dropped(): void
    {
        $path = $this->write('[{"id":"a"},{"id":"b"');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/truncated/');

        iterator_to_array((new JsonArrayStreamReader($path))->elements());
    }

    public function test_a_non_array_document_is_rejected(): void
    {
        $path = $this->write('{"id":"a"}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not a JSON array/');

        iterator_to_array((new JsonArrayStreamReader($path))->elements());
    }

    public function test_a_missing_file_is_reported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unable to open/');

        iterator_to_array((new JsonArrayStreamReader('/no/such/file.json'))->elements());
    }

    /**
     * The whole point of the reader: peak memory must not track file size.
     */
    public function test_memory_use_does_not_grow_with_the_number_of_elements(): void
    {
        $elements = array_map(
            static fn (int $i): string => '{"id":"'.$i.'","content":"'.str_repeat('x', 2000).'"}',
            range(1, 5000),
        );
        $path = $this->write('['.implode(',', $elements).']');

        $this->assertGreaterThan(10_000_000, filesize($path), 'The fixture should be ~10MB.');

        $before = memory_get_usage();
        $count = 0;

        foreach ((new JsonArrayStreamReader($path))->elements() as $element) {
            $count++;
        }

        $growth = memory_get_usage() - $before;

        $this->assertSame(5000, $count);
        $this->assertLessThan(
            1_000_000,
            $growth,
            'Streaming 10MB should not retain anything close to the file size.',
        );
    }

    public function test_it_reads_the_projects_real_corpus(): void
    {
        $elements = iterator_to_array((new JsonArrayStreamReader(base_path('database/seeders/data/data.json')))->elements());

        $this->assertCount(21, $elements);
        $this->assertArrayHasKey('published_at', $elements[0]);
        $this->assertArrayHasKey('title', $elements[0]);
    }

    private function write(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lifeweb-json-');
        file_put_contents($path, $contents);
        $this->files[] = $path;

        return $path;
    }
}
