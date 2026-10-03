<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flowie_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 100)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            // The nullable active slot enforces uniqueness without generating from a cascading foreign key.
            $table->unsignedTinyInteger('active_slot')
                ->nullable()->storedAs("CASE WHEN status = 'active' AND deleted_at IS NULL THEN 1 ELSE NULL END");
            $table->unique(['user_id', 'active_slot']);
            $table->index(['user_id', 'deleted_at', 'last_message_at']);
            $table->index(['deleted_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flowie_conversations');
    }
};
