<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('period', 16);

            // Keywords are an opaque, unordered filter payload handed to
            // Elasticsearch. Nothing queries or aggregates BY keyword in the
            // relational store, so a join table would add a join for no benefit.
            $table->json('keywords');
            $table->json('news_agency_ids')->nullable();
            $table->boolean('match_all_keywords')->default(false);

            $table->string('status', 16)->default('active');
            $table->timestamp('last_run_at')->nullable();

            // Stored, not derived: this makes the dispatcher's hot query a single
            // indexed range scan regardless of how many reports exist.
            $table->timestamp('next_run_at')->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The dispatcher's only hot query: active reports whose next run is due.
            $table->index(['status', 'next_run_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
