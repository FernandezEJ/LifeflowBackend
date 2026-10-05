<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rewards', function (Blueprint $table): void {
            $table->string('category', 20)->default('grocery')->index();
        });
    }

    public function down(): void
    {
        throw new LogicException('Reward categories are persisted inventory data. Roll forward instead.');
    }
};
