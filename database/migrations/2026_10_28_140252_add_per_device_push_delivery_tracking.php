<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->unsignedTinyInteger('push_delivery_version')->default(0);
            $table->unsignedBigInteger('push_fanout_cursor')->default(0);
            $table->unsignedBigInteger('push_fanout_max_device_id')->nullable();
            $table->timestamp('push_fanout_completed_at')->nullable();
            $table->index(['push_delivery_version', 'push_fanout_completed_at', 'id'], 'notifications_push_fanout');
        });
        Schema::create('notification_push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->unsignedBigInteger('device_token_id');
            $table->unsignedBigInteger('personal_access_token_id');
            $table->char('token_hash', 64);
            $table->string('state', 30)->default('pending');
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_code', 40)->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'device_token_id', 'personal_access_token_id', 'token_hash'], 'push_delivery_target');
            $table->index(['state', 'id']);
            $table->index(['state', 'attempted_at', 'id'], 'push_delivery_stalled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Attempt/acceptance tombstones must never disappear and allow an old queued job to replay.
        throw new LogicException('Push delivery history protects against duplicate alerts. Roll forward with owner approval.');
    }
};
