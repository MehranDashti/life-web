<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('report_id')->constrained()->cascadeOnDelete();

            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->string('status', 16)->default('pending');

            $table->unsignedInteger('rows')->default(0);
            $table->unsignedBigInteger('total_matched')->default(0);

            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            // Wall-clock and the engine's own reported query time are recorded
            // separately: the task asks for report generation time AND query
            // execution time, and one number cannot answer both.
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('query_took_ms')->nullable();
            $table->unsignedInteger('export_ms')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('delivery_error')->nullable();

            $table->timestamps();

            // THE idempotency guarantee. A duplicate dispatch, an overlapping
            // scheduler tick, or a redelivered queue message cannot produce a
            // second run for the same report and window. Enforced by the database
            // because a cache lock can be raced and a unique index cannot.
            $table->unique(['report_id', 'period_start', 'period_end'], 'report_runs_window_unique');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};
