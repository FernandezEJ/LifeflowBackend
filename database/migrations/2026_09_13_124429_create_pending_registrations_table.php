<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_registrations', function (Blueprint $table) {
            $table->char('email_hash', 64)->primary();
            $table->char('pending_token_hash', 64)->unique();
            $table->uuid('request_id')->unique();
            $table->text('payload')->nullable();
            $table->string('password_hash')->nullable();
            $table->string('code_hash')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('resend_at');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('delivery_claimed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_registrations');
    }
};
