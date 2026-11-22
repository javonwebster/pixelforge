<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // Also the Idempotency-Key and metadata.local_id sent to Pixelforge.
            $table->uuid('id')->primary();
            // Restrict, not cascade: money records outlive accounts, so the
            // admin report keeps matching /v1/usage.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // An automatic retry points at the generation it retries.
            $table->uuid('retry_of_id')->nullable();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('status', 32);

            $table->text('prompt');
            $table->string('size', 16);
            $table->string('quality', 16);
            $table->jsonb('reference_images')->default('[]');

            // Stored at submission. The hold is for max_price_nanos.
            $table->bigInteger('estimated_price_nanos');
            $table->bigInteger('max_price_nanos');

            // From Pixelforge: rates are fixed when it creates the generation.
            $table->jsonb('rates')->nullable();
            $table->integer('input_text_tokens')->nullable();
            $table->integer('input_image_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('reasoning_tokens')->nullable();

            // Written once, on success; exactly 0 on failure or rejection.
            $table->bigInteger('provider_cost_nanos')->nullable();
            $table->bigInteger('charge_nanos')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->string('last_submit_error')->nullable();

            $table->text('image_url')->nullable();
            $table->timestampTz('image_url_expires_at')->nullable();
            $table->string('stored_image_path')->nullable();
            $table->string('thumbnail_path')->nullable();

            $table->timestampTz('submitted_at')->nullable();
            // Pixelforge's completed_at, not our clock: it decides the
            // billing day in /v1/usage.
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        // Self-reference added after the table and its primary key exist.
        Schema::table('generations', function (Blueprint $table) {
            $table->foreign('retry_of_id')->references('id')->on('generations')->nullOnDelete();
        });

        DB::statement("ALTER TABLE generations ADD CONSTRAINT generations_status_check CHECK (status IN ('pending', 'submission_failed', 'queued', 'processing', 'succeeded', 'failed', 'rejected'))");
        DB::statement('ALTER TABLE generations ADD CONSTRAINT generations_prices_check CHECK (estimated_price_nanos >= 0 AND max_price_nanos >= estimated_price_nanos)');
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
