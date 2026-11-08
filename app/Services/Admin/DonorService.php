<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Services\DonorAchievementService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DonorService
{
    public function __construct(private DonorAchievementService $achievements) {}

    public function listing(array $filters = []): array
    {
        $donors = User::where('role', 'donor')->whereNull('deactivated_at');
        $eligibilityCounts = AssessmentSummary::counts($donors);
        $users = (clone $donors)
            ->leftJoin('donor_profiles', 'donor_profiles.user_id', '=', 'users.id')
            ->select(['users.id', 'users.name', 'users.created_at', 'donor_profiles.blood_type as bloodType'])
            ->selectSub(AssessmentSummary::latest(), 'latest_result')
            ->selectSub(AssessmentSummary::latest('assessment_version'), 'assessment_version')
            ->selectSub(AssessmentSummary::latestBucket(), 'assessment_bucket');
        $population = DB::query()->fromSub($users, 'donors');
        $bloodTypes = (clone $population)->selectRaw("COALESCE(bloodType, 'Unknown') as blood_type")
            ->distinct()->orderBy('blood_type')->pluck('blood_type')->all();
        $top = (clone $population)->leftJoinSub($this->achievements->completedCountsQuery(), 'completions',
            fn (JoinClause $join) => $join->on('donors.id', '=', 'completions.user_id'))
            ->select('donors.*')->selectRaw('COALESCE(completions.total, 0) as completed_total')
            ->orderByDesc('completed_total')->orderBy('donors.id')->limit(10)->get();

        $query = clone $population;
        $search = mb_strtolower(trim($filters['search'] ?? ''));
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn (Builder $query) => $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(COALESCE(bloodType, 'Unknown')) LIKE ? ESCAPE '!'", [$pattern]));
        }
        if (! empty($filters['blood_type']) && $filters['blood_type'] !== 'all') {
            $query->whereRaw("COALESCE(bloodType, 'Unknown') = ?", [$filters['blood_type']]);
        }
        match ($filters['eligibility'] ?? 'all') {
            'eligible', 'basic_requirements_met', 'needs_further_review', 'not_eligible', 'needs_further_screening' => $query->where('assessment_bucket', $filters['eligibility']),
            'temporarily_ineligible' => $query->whereIn('assessment_bucket', ['not_eligible', 'temporarily_ineligible']),
            'unassessed' => $query->whereNull('assessment_bucket'),
            'unknown' => $query->where('assessment_bucket', 'indeterminate'),
            'indeterminate' => $query->whereIn('assessment_bucket', ['indeterminate', 'needs_further_screening']),
            default => null,
        };
        $page = $query->orderByDesc('donors.created_at')->orderByDesc('donors.id')
            ->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
        $ids = $page->getCollection()->pluck('id')->merge($top->pluck('id'))->unique()->values()->all();
        $summaries = $this->achievements->summaries($ids);

        return [
            'summary' => [
                'totalDonors' => array_sum($eligibilityCounts),
                'eligible' => (int) ($eligibilityCounts['eligible'] ?? 0),
                'temporarilyIneligible' => (int) ($eligibilityCounts['not_eligible'] ?? 0) + (int) ($eligibilityCounts['temporarily_ineligible'] ?? 0),
            ],
            'assessment_counts' => $eligibilityCounts,
            'donors' => $page->getCollection()->map(fn (object $user): array => $this->row($user, $summaries))->all(),
            'topDonors' => $top->map(fn (object $user): array => $this->row($user, $summaries))->all(),
            'bloodTypes' => $bloodTypes,
            'pagination' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    private function row(object $user, array $summaries): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'bloodType' => $user->bloodType,
            'eligibility' => match ($user->assessment_bucket) {
                'eligible' => 'eligible',
                'not_eligible', 'temporarily_ineligible' => 'temporarily_ineligible',
                'basic_requirements_met', 'needs_further_review' => $user->assessment_bucket,
                null => 'unassessed',
                default => 'indeterminate',
            },
            'assessment' => ['result' => $user->latest_result, 'version' => $user->assessment_version],
            'totalDonations' => $summaries[$user->id]['total_donations'],
            'achievement' => $summaries[$user->id]['achievement']['label'],
            'joinedDate' => $user->created_at === null ? null : Carbon::parse($user->created_at, config('app.timezone'))
                ->timezone(config('app.calendar_timezone'))->toDateString(),
        ];
    }
}
