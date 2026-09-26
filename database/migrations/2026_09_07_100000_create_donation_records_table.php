<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // ========================================
    // DONATION VERIFICATION RECORDS
    // Stores donor submissions separately from profiles and assessments.
    // Pending is the default; future authorized staff control verification.
    // ========================================
    public function up(): void
    {
        Schema::create('donation_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('donation_date');
            $table->string('location', 255);
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'completed', 'rejected'])->default('pending');
            $table->timestamp('submitted_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'donation_date', 'id']);
            $table->index(['user_id', 'status']);
        });
    }

    // ========================================
    // STANDARD REVERSAL
    // Defines Laravel's rollback; setup runs only the additive up migration.
    // ========================================
    public function down(): void
    {
        Schema::dropIfExists('donation_records');
    }
};
