<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Set the first time a submit gets no clean answer (a timeout, no
     * connection, an unrecognised response), and never cleared: from then
     * on Pixelforge may have the generation, whatever later attempts hear.
     * last_submit_error only holds the latest answer, so a timeout followed
     * by 503s would otherwise look like a clean refusal when the job gives up.
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->boolean('outcome_unknown')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('outcome_unknown');
        });
    }
};
