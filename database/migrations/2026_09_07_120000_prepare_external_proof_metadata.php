<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // ========================================
    // EXTERNAL PROOF REFERENCES
    // Preserves existing paths for legacy history and adds an external URL.
    // This does not upload, move, or delete files or configure Firebase.
    // ========================================
    public function up(): void
    {
        Schema::table('donation_participations', function (Blueprint $table) {
            $table->renameColumn('proof_path', 'proof_storage_path');
            $table->text('proof_url')->nullable();
        });
    }

    // ========================================
    // STANDARD REVERSAL
    // Restores the previous column names only on an explicitly requested rollback.
    // ========================================
    public function down(): void
    {
        Schema::table('donation_participations', function (Blueprint $table) {
            $table->renameColumn('proof_storage_path', 'proof_path');
            $table->dropColumn('proof_url');
        });
    }
};
