<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // ========================================
    // ASSESSMENT HISTORY
    // Saves answer snapshots and calculated results separately from donor profiles.
    // ========================================
    public function up(): void
    {
        Schema::create('eligibility_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('result', ['eligible', 'temporarily_ineligible', 'needs_further_screening']);
            $table->json('reasons');
            $table->json('answers');
            $table->timestamp('assessed_at');
            $table->timestamps();
            $table->index(['user_id', 'assessed_at', 'id']);
        });
    }

    // ========================================
    // ROLLBACK DEFINITION
    // Standard Laravel reversal; deployment runs only the additive up migration.
    // ========================================
    public function down(): void
    {
        Schema::dropIfExists('eligibility_assessments');
    }
};
