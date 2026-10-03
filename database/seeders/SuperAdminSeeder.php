<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $name = config('admin.super_admin.name');
        $email = config('admin.super_admin.email');
        $password = config('admin.super_admin.password');

        if (! is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 255
            || ! is_string($email) || ! filter_var(trim($email), FILTER_VALIDATE_EMAIL) || strlen(trim($email)) > 255
            || ! is_string($password) || strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new RuntimeException('Set valid SUPER_ADMIN_NAME, SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD (8–72 bytes) before running this seeder.');
        }

        DB::transaction(function () use ($name, $email, $password): void {
            $email = strtolower(trim($email));
            $user = User::withTrashed()->where('email', $email)->lockForUpdate()->first();

            if ($user && (! $user->isSuperAdmin() || $user->trashed())) {
                throw new RuntimeException('The configured email belongs to an existing non-Super-Admin or deleted account. No account was changed.');
            }

            if (User::withTrashed()->where('role', UserRole::SuperAdmin->value)->where('email', '!=', $email)->exists()) {
                throw new RuntimeException('A different Super Admin already exists. This seeder only bootstraps the first Super Admin.');
            }

            $user ??= new User;
            $user->name = trim($name);
            $user->email = $email;
            if (! $user->exists || ! Hash::check($password, $user->password)) {
                $user->password = Hash::make($password);
            }
            $user->role = UserRole::SuperAdmin;
            $user->must_change_password = true;
            $user->deactivated_at = null;
            $user->save();
        });
    }
}
