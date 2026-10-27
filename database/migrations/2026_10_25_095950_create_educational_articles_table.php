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
        Schema::create('educational_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title')->default('');
            $table->string('category')->nullable()->index();
            $table->string('short_description', 160)->default('');
            $table->text('description')->nullable();
            $table->json('description_document')->nullable();
            $table->string('image_path')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamp('published_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('educational_articles');
    }
};
