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
        Schema::table('rewards', function (Blueprint $table): void {
            $table->unsignedBigInteger('inventory_version')->default(0);
        });
        Schema::create('reward_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reward_id')->constrained()->cascadeOnDelete();
            $table->string('size', 3);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->timestamps();
            $table->unique(['reward_id', 'size']);
        });
        Schema::table('user_vouchers', function (Blueprint $table): void {
            $table->foreignId('reward_variant_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_vouchers', fn (Blueprint $table) => $table->dropConstrainedForeignId('reward_variant_id'));
        Schema::dropIfExists('reward_variants');
        Schema::table('rewards', fn (Blueprint $table) => $table->dropColumn('inventory_version'));
    }
};
