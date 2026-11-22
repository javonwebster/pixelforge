<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each event is stored once; repeat deliveries of it are counted here
     * (Pixelforge's [duplicate] keyword, at-least-once retries, dashboard
     * resends), so History can show that they arrived and were ignored.
     */
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->unsignedInteger('duplicate_deliveries')->default(0);
            $table->timestampTz('last_duplicate_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropColumn(['duplicate_deliveries', 'last_duplicate_at']);
        });
    }
};
