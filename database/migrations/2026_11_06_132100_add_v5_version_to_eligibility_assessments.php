<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('eligibility_assessments', function (Blueprint $table) {
            $table->string('assessment_version', 20)->nullable();
            $table->enum('result', ['eligible', 'temporarily_ineligible', 'needs_further_screening', 'not_eligible',
                'basic_requirements_met', 'needs_further_review'])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reversing without a data review must never relabel or discard V5 history.
        if (DB::table('eligibility_assessments')->whereNotNull('assessment_version')
            ->orWhereIn('result', ['basic_requirements_met', 'needs_further_review'])->exists()) {
            throw new LogicException('Preserve V5 history: rollback requires an owner-approved data retention plan.');
        }
        Schema::table('eligibility_assessments', function (Blueprint $table) {
            $table->dropColumn('assessment_version');
            $table->enum('result', ['eligible', 'temporarily_ineligible', 'needs_further_screening', 'not_eligible'])->change();
        });
    }
};
