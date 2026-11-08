<?php

namespace App\Services\Admin;

use App\Models\EligibilityAssessment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AssessmentSummary
{
    public const BUCKETS = ['basic_requirements_met', 'needs_further_review', 'eligible', 'not_eligible',
        'temporarily_ineligible', 'needs_further_screening', 'unassessed', 'indeterminate'];

    /** A saved outcome only, never a fresh medical clearance or participation decision. */
    public static function latest(string $column = 'result'): Builder
    {
        return EligibilityAssessment::select($column)->whereColumn('user_id', 'users.id')
            ->orderByDesc('assessed_at')->orderByDesc('id')->limit(1);
    }

    public static function latestBucket(): Builder
    {
        return self::latest()->select([])->selectRaw("CASE
            WHEN assessment_version = 'v5' AND result IN ('basic_requirements_met', 'needs_further_review') THEN result
            WHEN assessment_version IS NULL AND result IN ('eligible', 'not_eligible', 'temporarily_ineligible', 'needs_further_screening') THEN result
            ELSE 'indeterminate' END");
    }

    /** Latest timestamp, then ID, once per current donor; no answers are selected. */
    public static function counts(Builder $donors): array
    {
        $latest = (clone $donors)->select('users.id')->selectSub(self::latestBucket(), 'assessment_bucket');
        $counts = DB::query()->fromSub($latest, 'assessments')
            ->selectRaw("COALESCE(assessment_bucket, 'unassessed') as bucket, COUNT(*) as total")
            ->groupBy('assessment_bucket')->pluck('total', 'bucket');

        return collect(self::BUCKETS)->mapWithKeys(fn (string $bucket): array => [$bucket => (int) ($counts[$bucket] ?? 0)])->all();
    }
}
