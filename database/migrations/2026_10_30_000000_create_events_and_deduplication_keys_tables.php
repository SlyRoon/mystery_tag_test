<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->text('click_id');
            $table->text('event_name');
            $table->text('amount_canonical');
            $table->dateTime('accepted_at', precision: 6);
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->string('last_error', 512)->nullable();
            $table->dateTime('sent_at', precision: 6)->nullable();
            $table->index([new Expression('accepted_at DESC'), new Expression('id DESC')], 'events_accepted_at_id_index');
        });

        Schema::create('deduplication_keys', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->binary('fingerprint', 32, true)->primary();
            $table->dateTime('accepted_at', precision: 6)->nullable();
            $table->dateTime('expires_at', precision: 6)->nullable();
            $table->foreignId('event_id')->nullable()
                ->constrained('events')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deduplication_keys');
        Schema::dropIfExists('events');
    }
};
