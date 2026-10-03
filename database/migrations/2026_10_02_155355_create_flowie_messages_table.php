<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flowie_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flowie_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->text('content');
            $table->timestamps();
            $table->index(['flowie_conversation_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flowie_messages');
    }
};
