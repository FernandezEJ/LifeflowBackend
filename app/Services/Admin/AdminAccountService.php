<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminAccountService
{
    /** @return array{admin: User, temporary_password: string} */
    public function create(User $actor, string $name, string $email): array
    {
        try {
            return DB::transaction(function () use ($actor, $name, $email): array {
                $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                abort_unless($actor->isSuperAdmin() && ! $actor->isDeactivated() && ! $actor->must_change_password, 403, 'Access denied.');
                do {
                    $temporaryPassword = Str::password(20);
                } while (! preg_match('/[a-z]/', $temporaryPassword) || ! preg_match('/[A-Z]/', $temporaryPassword));
                $admin = new User;
                $admin->name = $name;
                $admin->email = $email;
                $admin->password = Hash::make($temporaryPassword);
                $admin->role = UserRole::Admin;
                $admin->must_change_password = true;
                $admin->deactivated_at = null;
                $admin->last_login_at = null;
                $admin->save();
                $actor->auditLogs()->create([
                    'actor_role' => $actor->role, 'action' => 'admin_created', 'module' => 'admin_accounts',
                    'target_type' => 'user', 'target_id' => $admin->id,
                ]);

                return ['admin' => $admin, 'temporary_password' => $temporaryPassword];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'The email has already been taken.']);
        }
    }

    public function changeInitialPassword(User $actor, string $currentPassword, string $password): void
    {
        DB::transaction(function () use ($actor, $currentPassword, $password): void {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->isAdminPanelUser() && ! $user->isDeactivated(), 403, 'Access denied.');
            abort_unless($user->must_change_password, 409, 'Initial password has already been changed.');
            $tokenId = $actor->currentAccessToken()?->getKey();
            abort_unless($tokenId && $user->tokens()->whereKey($tokenId)->exists(), 401, 'Unauthenticated.');

            // Recheck under the row lock so concurrent password changes cannot use a stale hash.
            if (! Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The password is incorrect.']);
            }
            $user->password = Hash::make($password);
            $user->must_change_password = false;
            $user->save();
            $user->tokens()->where('id', '!=', $tokenId)->delete();
            $user->auditLogs()->create([
                'actor_role' => $user->role, 'action' => 'initial_password_changed', 'module' => 'admin_accounts',
                'target_type' => 'user', 'target_id' => $user->id,
            ]);
        });
    }
}
