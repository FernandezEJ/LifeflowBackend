<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // ========================================
    // OPPORTUNITIES AND DONOR ACTIVITY
    // Adds the corrected lifecycle without replacing historical donation records.
    // ========================================
    public function up(): void
    {
        Schema::create('donation_opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description');
            $table->string('location');
            $table->date('event_date');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedInteger('points_reward')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->enum('status', ['draft', 'published', 'expired', 'cancelled'])->default('draft');
            $table->timestamps();
            $table->index(['status', 'expires_at']);
        });
        Schema::create('donation_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('donation_opportunity_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['pending', 'for_verification', 'completed', 'rejected', 'cancelled'])->default('pending');
            $table->timestamp('joined_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('proof_original_name')->nullable();
            $table->string('proof_mime_type')->nullable();
            $table->timestamp('proof_uploaded_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'donation_opportunity_id', 'status'], 'participation_owner_opportunity_status');
        });
        Schema::table('donation_records', function (Blueprint $table) {
            $table->foreignId('donation_participation_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
    }

    // ========================================
    // STANDARD REVERSAL
    // Only used for a separately authorized rollback, never normal setup.
    // ========================================
    public function down(): void
    {
        Schema::table('donation_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('donation_participation_id');
        });
        Schema::dropIfExists('donation_participations');
        Schema::dropIfExists('donation_opportunities');
    }
};
