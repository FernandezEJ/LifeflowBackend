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
        Schema::table('donation_opportunities', function (Blueprint $table) {
            $table->json('description_document')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('donation_opportunities', function (Blueprint $table) {
            $table->dropColumn('description_document');
        });
    }
};
