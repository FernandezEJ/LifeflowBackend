<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_changes', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->constrained()->cascadeOnDelete();
            $table->string('pending_token_hash', 64)->unique();
            $table->uuid('request_id')->unique();
            $table->text('new_email');
            $table->string('original_email_hash', 64);
            $table->string('credential_hash', 64);
            $table->string('code_hash')->nullable();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('resend_at');
            $table->timestamp('delivery_claimed_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_changes');
    }
};
