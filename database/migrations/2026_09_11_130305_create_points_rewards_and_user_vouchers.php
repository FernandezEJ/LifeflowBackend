<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // ========================================
    // LEDGER, FINITE INVENTORY AND OWNED VOUCHERS
    // Adds empty tables without changing existing donor data.
    // Unique event/request keys protect retries at the database boundary.
    // ========================================
    public function up(): void
    {
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['donation_reward', 'reward_redemption']);
            $table->bigInteger('amount');
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('description')->nullable();
            $table->string('event_key', 150);
            $table->timestamps();
            $table->unique(['user_id', 'event_key']);
            $table->index(['user_id', 'id']);
        });
        Schema::create('rewards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('points_cost');
            $table->decimal('voucher_value', 12, 2)->nullable();
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->text('image_url')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->index(['active', 'starts_at', 'ends_at']);
        });
        Schema::create('user_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reward_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('points_spent');
            $table->enum('status', ['available', 'active', 'redeemed', 'expired'])->default('available');
            $table->string('voucher_token', 64)->unique();
            $table->uuid('redemption_key');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'redemption_key']);
            $table->index(['user_id', 'status', 'id']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_vouchers');
        Schema::dropIfExists('rewards');
        Schema::dropIfExists('point_transactions');
    }
};
