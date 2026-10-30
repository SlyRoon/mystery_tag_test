<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->binary('delivery_token', 16, true)->nullable();
            $table->dateTime('delivery_lease_until', precision: 6)->nullable();
            $table->dateTime('next_attempt_at', precision: 6)->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['delivery_token', 'delivery_lease_until', 'next_attempt_at']);
        });
    }
};
