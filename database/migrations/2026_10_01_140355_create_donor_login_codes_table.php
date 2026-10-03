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
        Schema::create('donor_login_codes', function (Blueprint $table) {
            // Login is separate from registration verification and password recovery.
            $table->char('mobile_hash', 64)->primary();
            $table->uuid('request_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('email_hash', 64)->nullable();
            $table->string('purpose')->default('donor_login');
            $table->string('code_hash')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('resend_at');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('delivery_claimed_at')->nullable();
            $table->timestamp('used_at')->nullable();
            // A consumed current-email OTP can authorize one email change on this session.
            $table->foreignId('confirmed_token_id')->nullable()->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('confirmation_used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donor_login_codes');
    }
};
