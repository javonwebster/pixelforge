<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every verified delivery, once. Doubles as an audit trail.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            // Pixelforge delivers at least once; this unique index is
            // what turns a duplicate delivery into a no-op.
            $table->string('event_id')->unique();
            $table->string('type');
            $table->string('pixelforge_generation_id')->nullable()->index();
            $table->unsignedInteger('delivery_attempt')->nullable();
            $table->jsonb('payload');
            $table->timestampTz('received_at');
            // Null while the event matches no local generation; reconcile
            // retries those.
            $table->timestampTz('processed_at')->nullable();
        });

        DB::statement('CREATE INDEX webhook_events_unprocessed_index ON webhook_events (received_at) WHERE processed_at IS NULL');

        // At most one automatic retry per generation, enforced by the
        // database as well as by the updater's row lock.
        DB::statement('CREATE UNIQUE INDEX generations_one_retry_per_original ON generations (retry_of_id) WHERE retry_of_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS generations_one_retry_per_original');
        Schema::dropIfExists('webhook_events');
    }
};
