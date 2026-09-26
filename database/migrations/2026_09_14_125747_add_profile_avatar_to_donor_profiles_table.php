<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donor_profiles', function (Blueprint $table): void {
            // Stable identifiers only. Existing and future donors use mascot_1 by default.
            $table->enum('profile_avatar', ['mascot_1', 'mascot_2', 'mascot_3', 'mascot_4', 'mascot_5'])->default('mascot_1');
        });
    }

    public function down(): void
    {
        Schema::table('donor_profiles', function (Blueprint $table): void {
            $table->dropColumn('profile_avatar');
        });
    }
};
