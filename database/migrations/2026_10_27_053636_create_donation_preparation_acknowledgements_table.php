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
        $name = 'donation_preparation_acknowledgements';
        // MySQL may retain the new table if a later DDL statement fails.
        // Resume without dropping that table or any acknowledgement records.
        if (! Schema::hasTable($name)) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id');
                $table->foreignId('donation_opportunity_id');
                $table->timestamp('acknowledged_at');
            });
        }
        $keys = collect(Schema::getForeignKeys($name));
        if (! $keys->contains(fn (array $key) => $key['columns'] === ['user_id'])) {
            Schema::table($name, fn (Blueprint $table) => $table->foreign('user_id', 'preparation_user_foreign')->references('id')->on('users')->cascadeOnDelete());
        }
        if (! $keys->contains(fn (array $key) => $key['columns'] === ['donation_opportunity_id'])) {
            Schema::table($name, fn (Blueprint $table) => $table->foreign('donation_opportunity_id', 'preparation_activity_foreign')->references('id')->on('donation_opportunities')->cascadeOnDelete());
        }
        if (! Schema::hasIndex($name, 'preparation_donor_activity_unique')) {
            Schema::table($name, fn (Blueprint $table) => $table->unique(['user_id', 'donation_opportunity_id'], 'preparation_donor_activity_unique'));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donation_preparation_acknowledgements');
    }
};
