<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('generation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->bigInteger('amount_nanos');
            $table->timestampTz('created_at')->useCurrent();

            // The database-level guarantee that a generation is held,
            // released and charged at most once each. Purchases have a null
            // generation_id, and Postgres treats nulls as distinct, so a
            // user can have any number of them.
            $table->unique(['generation_id', 'type']);
            $table->index('user_id');
        });

        // Signs are part of the meaning of each type; enforce them here too.
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_type_check CHECK (
            (type = 'purchase' AND amount_nanos > 0 AND generation_id IS NULL)
            OR (type = 'hold' AND amount_nanos < 0 AND generation_id IS NOT NULL)
            OR (type = 'release' AND amount_nanos > 0 AND generation_id IS NOT NULL)
            OR (type = 'charge' AND amount_nanos < 0 AND generation_id IS NOT NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
