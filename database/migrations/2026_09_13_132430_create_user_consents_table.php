<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_consents', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('accepted_terms');
            $table->boolean('acknowledged_privacy');
            $table->boolean('acknowledged_prescreening');
            $table->string('terms_version', 20);
            $table->string('privacy_version', 20);
            $table->timestamp('accepted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_consents');
    }
};
