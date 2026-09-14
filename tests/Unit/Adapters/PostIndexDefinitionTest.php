<?php

declare(strict_types=1);

namespace Tests\Unit\Adapters;

use Tests\TestCase;
use Illuminate\Support\Carbon;
use App\Adapters\Elasticsearch\PostIndexDefinition;

class PostIndexDefinitionTest extends TestCase
{
    public function test_documents_are_routed_to_a_monthly_index(): void
    {
        $this->assertSame(
            'posts-2024.12',
            PostIndexDefinition::indexFor(Carbon::parse('2024-12-21T08:26:47Z')),
        );
    }

    public function test_the_template_attaches_the_read_alias_to_every_monthly_index(): void
    {
        $template = PostIndexDefinition::template('posts-*', 'posts', 1, 0);

        $this->assertSame(['posts-*'], $template['index_patterns']);
        $this->assertArrayHasKey('posts', $template['template']['aliases']);
    }

    /**
     * Persian and Arabic spellings of the same word must land on the same token,
     * or a keyword search silently misses documents from half the feeds.
     */
    public function test_the_analyzer_normalises_arabic_and_persian_forms(): void
    {
        $filters = PostIndexDefinition::settings(1, 0)['analysis']['analyzer'][PostIndexDefinition::ANALYZER]['filter'];

        $this->assertContains('arabic_normalization', $filters);
        $this->assertContains('persian_normalization', $filters);
        $this->assertContains('decimal_digit', $filters);
    }

    /**
     * Strict dynamic mapping: an unexpected field in a feed must fail the index
     * request loudly rather than silently create an unanalysed field.
     */
    public function test_the_mapping_rejects_unknown_fields(): void
    {
        $this->assertSame('strict', PostIndexDefinition::mappings()['dynamic']);
    }

    public function test_keyword_search_covers_title_lead_and_content(): void
    {
        $fields = PostIndexDefinition::searchFields();

        $this->assertContains('title^3', $fields);
        $this->assertContains('lead^2', $fields);
        $this->assertContains('content', $fields);
    }
}
