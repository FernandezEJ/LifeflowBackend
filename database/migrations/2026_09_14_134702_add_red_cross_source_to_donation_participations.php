<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_participations', function (Blueprint $table): void {
            $table->enum('source_type', ['admin_announcement', 'red_cross_dagupan'])->default('admin_announcement');
            // Keep the existing foreign key for admin posts; the permanent system source has no post.
            $table->unsignedBigInteger('donation_opportunity_id')->nullable()->change();
            $table->index(['user_id', 'source_type', 'status'], 'participation_owner_source_status');
        });
    }

    public function down(): void
    {
        if (DB::table('donation_participations')->whereNull('donation_opportunity_id')->exists()) {
            throw new RuntimeException('Cannot remove source support while system-source donation history exists.');
        }
        Schema::table('donation_participations', function (Blueprint $table): void {
            $table->dropIndex('participation_owner_source_status');
            $table->dropColumn('source_type');
            $table->unsignedBigInteger('donation_opportunity_id')->nullable(false)->change();
        });
    }
};
