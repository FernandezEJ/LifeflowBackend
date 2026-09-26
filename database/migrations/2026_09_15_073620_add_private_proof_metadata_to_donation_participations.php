<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_participations', function (Blueprint $table) {
            $table->string('proof_path')->nullable();
            $table->unsignedBigInteger('proof_size')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('donation_participations', function (Blueprint $table) {
            $table->dropColumn(['proof_path', 'proof_size']);
        });
    }
};
