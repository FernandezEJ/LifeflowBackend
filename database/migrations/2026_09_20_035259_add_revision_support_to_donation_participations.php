<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_participations', function (Blueprint $table) {
            $table->enum('status', ['pending', 'for_verification', 'needs_revision', 'completed', 'rejected', 'cancelled'])->default('pending')->change();
            $table->text('revision_reason')->nullable();
        });
    }

    public function down(): void
    {
        throw new LogicException('Revision history must be reviewed before narrowing statuses or removing revision reasons.');
    }
};
