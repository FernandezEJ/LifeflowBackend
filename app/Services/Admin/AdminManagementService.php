<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\PasswordResetService;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminManagementService
{
    public function listing(array $filters): array
    {
        $base = User::where('role', UserRole::Admin->value);
        $summary = ['total' => (clone $base)->count(), 'active' => (clone $base)->whereNull('deactivated_at')->count(),
            'deactivated' => (clone $base)->whereNotNull('deactivated_at')->count()];
        $status = $filters['status'] ?? 'all';
        if ($status === 'active') {
            $base->whereNull('deactivated_at');
        } elseif ($status === 'deactivated') {
            $base->whereNotNull('deactivated_at');
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $base->where(function ($query) use ($search): void {
                $query->whereLike('name', '%'.$search.'%')->orWhereLike('email', '%'.$search.'%');
            });
        }

        return ['admins' => $base->orderByDesc('id')->get()->map(fn (User $user): array => $this->profile($user))->all(), 'summary' => $summary];
    }

    public function profile(User $admin): array
    {
        return ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'role' => $admin->role->value,
            'status' => $admin->isDeactivated() ? 'deactivated' : 'active',
            'must_change_password' => $admin->must_change_password,
            'deactivated_at' => $admin->deactivated_at?->toISOString(),
            'scheduled_for_deletion_at' => $admin->deactivated_at?->copy()->addDays(30)->toISOString(),
            'last_login_at' => $admin->last_login_at?->toISOString(), 'created_at' => $admin->created_at?->toISOString()];
    }

    private function mutate(User $actor, int $id, string $action, Closure $change): User
    {
        return DB::transaction(function () use ($actor, $id, $action, $change): User {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->isSuperAdmin() && ! $actor->isDeactivated() && ! $actor->must_change_password, 403, 'Access denied.');
            $admin = User::where('role', UserRole::Admin->value)->whereKey($id)->lockForUpdate()->firstOrFail();
            $change($admin);
            $actor->auditLogs()->create(['actor_role' => $actor->role, 'action' => $action, 'module' => 'admin_accounts',
                'target_type' => 'user', 'target_id' => $admin->id]);

            return $admin;
        });
    }

    public function update(User $actor, int $id, array $data): User
    {
        try {
            return $this->mutate($actor, $id, 'admin_updated', function (User $admin) use ($data): void {
                $admin->name = $data['name'];
                $admin->email = $data['email'];
                $admin->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }
    }

    public function deactivate(User $actor, int $id): User
    {
        return $this->mutate($actor, $id, 'admin_deactivated', function (User $admin): void {
            abort_if($admin->isDeactivated(), 409, 'Admin is already deactivated.');
            $admin->deactivated_at = now()->startOfSecond();
            $admin->save();
            $admin->tokens()->delete();
            DB::table('sessions')->where('user_id', $admin->id)->delete();
        });
    }

    public function reactivate(User $actor, int $id): User
    {
        return $this->mutate($actor, $id, 'admin_reactivated', function (User $admin): void {
            abort_unless($admin->isDeactivated(), 409, 'Admin is already active.');
            abort_if($admin->deactivated_at->lte(now()->subDays(30)), 409, 'The 30-day recovery period has ended.');
            $admin->deactivated_at = null;
            $admin->save();
        });
    }

    public function sendPasswordReset(User $actor, int $id): void
    {
        $this->mutate($actor, $id, 'admin_password_reset_requested', function (User $admin): void {
            abort_if($admin->isDeactivated(), 409, 'Reactivate the Admin before requesting a password reset.');
            app(PasswordResetService::class)->requestCode(strtolower($admin->email));
        });
    }

    /** @return array{purged: int, retained: int} */
    public function purge(): array
    {
        $counts = ['purged' => 0, 'retained' => 0];
        User::withTrashed()->where('role', UserRole::Admin->value)->where('deactivated_at', '<=', now()->subDays(30))
            ->select('id')->chunkById(100, function ($rows) use (&$counts): void {
                foreach ($rows as $row) {
                    $result = DB::transaction(function () use ($row): ?string {
                        $admin = User::withTrashed()->whereKey($row->id)->lockForUpdate()->first();
                        if (! $admin || ! $admin->isAdmin() || ! $admin->deactivated_at || $admin->deactivated_at->gt(now()->subDays(30))) {
                            return null;
                        }
                        // Existing donor FKs cascade on user deletion. Retain accounts with history instead of destroying it.
                        foreach (['donor_profiles', 'donation_records', 'donation_participations', 'eligibility_assessments',
                            'point_transactions', 'user_vouchers', 'notifications', 'user_consents'] as $table) {
                            if (DB::table($table)->where('user_id', $admin->id)->exists()) {
                                return 'retained';
                            }
                        }
                        AuditLog::create(['actor_user_id' => null, 'actor_role' => UserRole::Admin, 'action' => 'admin_purged',
                            'module' => 'admin_accounts', 'target_type' => 'user', 'target_id' => $admin->id,
                            'details' => ['initiator' => 'scheduler']]);
                        $admin->tokens()->delete();
                        DB::table('sessions')->where('user_id', $admin->id)->delete();
                        DB::table('password_reset_tokens')->where('email', $admin->email)->delete();
                        $admin->forceDelete();

                        return 'purged';
                    });
                    if ($result) {
                        $counts[$result]++;
                    }
                }
            });

        return $counts;
    }
}
