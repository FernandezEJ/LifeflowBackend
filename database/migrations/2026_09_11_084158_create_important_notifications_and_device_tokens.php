<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // ========================================
    // PRIVATE HISTORY AND SESSION-BOUND DEVICES
    // Adds tables only; existing donor and donation data remains intact.
    // ========================================
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title');
            $table->text('message');
            $table->json('data')->nullable();
            $table->string('event_key', 150);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('push_attempted_at')->nullable();
            $table->string('push_status', 30)->default('pending');
            $table->timestamps();
            $table->unique(['user_id', 'event_key']);
            $table->index(['user_id', 'read_at', 'id']);
        });
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->constrained()->cascadeOnDelete();
            $table->text('token');
            $table->char('token_hash', 64)->unique();
            $table->string('platform', 20);
            $table->string('provider', 20);
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });
    }

    // ========================================
    // EXPLICIT MIGRATION REVERSAL
    // Used only if a rollback is separately requested.
    // ========================================
    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('notifications');
    }
};
