<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Append the new result without translating or deleting historical values.
    public function up(): void
    {
        Schema::table('eligibility_assessments', function (Blueprint $table) {
            $table->enum('result', ['eligible', 'temporarily_ineligible', 'needs_further_screening', 'not_eligible'])->change();
        });
    }

    // Narrowing this enum could corrupt newer history. Roll forward instead.
    public function down(): void
    {
        throw new LogicException('This compatibility migration cannot be rolled back without reviewing saved assessment results.');
    }
};
