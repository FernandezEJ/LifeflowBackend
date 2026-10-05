<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuditLogService
{
    public function listing(array $filters): array
    {
        $query = AuditLog::query();
        if (! empty($filters['module'])) {
            $query->where('module', $filters['module']);
        }
        if (! empty($filters['month'])) {
            $localTime = DB::getDriverName() === 'sqlite'
                ? "datetime(created_at, '+8 hours')" : 'DATE_ADD(created_at, INTERVAL 8 HOUR)';
            $query->whereMonth(DB::raw($localTime), (int) $filters['month']);
        }
        $search = mb_strtolower(trim($filters['search'] ?? ''));
        if ($search !== '') {
            $this->search($query, $search);
        }
        $page = $query->with('actor:id,name')->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'data' => $page->getCollection()->map(fn (AuditLog $log): array => $this->row($log))->all(),
            'pagination' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(), 'total' => $page->total()],
            'modules' => AuditLog::query()->distinct()->orderBy('module')->pluck('module')->all(),
        ];
    }

    /** Search only displayable metadata, never credentials or arbitrary request payloads. */
    private function search(Builder $query, string $search): void
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        $sqlite = DB::getDriverName() === 'sqlite';
        $reason = $sqlite ? "json_extract(details, '$.reason')" : "JSON_UNQUOTE(JSON_EXTRACT(details, '$.reason'))";
        $reasonType = $sqlite ? "json_type(details, '$.reason') = 'text'" : "JSON_TYPE(JSON_EXTRACT(details, '$.reason')) = 'STRING'";
        $query->where(function (Builder $query) use ($pattern, $reason, $reasonType, $search): void {
            $query->whereRaw("LOWER(REPLACE(action, '_', ' ')) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(action) LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereHas('actor', fn (Builder $actor) => $actor->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhere(fn (Builder $details) => $details->whereRaw($reasonType)
                    ->whereRaw("LOWER($reason) LIKE ? ESCAPE '!'", [$pattern]));
            if (str_contains('initiator: scheduler', $search)) {
                $query->orWhere('details->initiator', 'scheduler');
            }
            if (str_contains('system', $search)) {
                $query->orWhere(fn (Builder $system) => $system->whereDoesntHave('actor')->where('details->initiator', 'scheduler'));
            }
            if (str_contains('unknown user', $search)) {
                $query->orWhere(fn (Builder $unknown) => $unknown->whereDoesntHave('actor')
                    ->where(fn (Builder $details) => $details->whereNull('details->initiator')->orWhere('details->initiator', '!=', 'scheduler')));
            }
        });
    }

    /** Explicit display allowlist; actor role is the historical snapshot, not today's role. */
    private function row(AuditLog $log): array
    {
        $details = is_array($log->details) ? $log->details : [];
        $scheduler = ($details['initiator'] ?? null) === 'scheduler';
        $display = [];
        if (is_string($details['reason'] ?? null) && trim($details['reason']) !== '') {
            $display[] = $details['reason'];
        }
        if ($scheduler) {
            $display[] = 'Initiator: Scheduler';
        }
        $type = $log->target_type ? Str::headline(class_basename($log->target_type)) : null;

        return [
            'id' => $log->id,
            'actorName' => $log->actor?->name ?? ($scheduler ? 'System' : 'Unknown User'),
            'actorRole' => UserRole::tryFrom($log->getRawOriginal('actor_role'))?->value,
            'action' => $log->action,
            'module' => $log->module,
            'target' => $type ? $type.($log->target_id !== null ? ' #'.$log->target_id : '') : null,
            'details' => $display === [] ? null : implode(' · ', $display),
            'createdAt' => $log->created_at?->toISOString(),
        ];
    }
}
