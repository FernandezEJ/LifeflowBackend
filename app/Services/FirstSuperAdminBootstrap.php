<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Throwable;

class FirstSuperAdminBootstrap
{
    public const AUDIT_ACTION = 'super_admin_bootstrapped';

    public function create(string $name, string $email, #[\SensitiveParameter] string $password): void
    {
        if (! app()->environment('production')) {
            throw new RuntimeException('Bootstrap requires the production environment.');
        }
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        $validator = Validator::make(['name' => $name, 'email' => $email, 'password' => $password], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', Password::min(10)->mixedCase()->numbers()->symbols()],
        ]);
        if ($validator->fails() || strlen($password) > 72 || str_contains($password, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $name.$email)) {
            throw new RuntimeException('Bootstrap input does not satisfy the staff credential policy.');
        }
        $connection = DB::connection();
        $lock = 'lifeflow:first-staff:'.substr(hash('sha256', $connection->getDatabaseName()), 0, 32);
        if (! $this->acquire($connection, $lock)) {
            throw new RuntimeException('Another bootstrap is already running.');
        }
        try {
            $connection->transaction(function () use ($connection, $lock, $name, $email, $password): void {
                $this->assertOwned($connection, $lock);
                if (User::on($connection->getName())->withTrashed()->whereIn('role', [UserRole::Admin->value, UserRole::SuperAdmin->value])->exists()
                    || AuditLog::on($connection->getName())->where('action', self::AUDIT_ACTION)->exists()) {
                    throw new RuntimeException('Bootstrap has already been consumed or staff history exists.');
                }
                if (User::on($connection->getName())->withTrashed()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                    throw new RuntimeException('Bootstrap identity conflicts with an existing account.');
                }
                $user = new User;
                $user->setConnection($connection->getName());
                $user->name = $name;
                $user->email = $email;
                $user->password = Hash::make($password);
                $user->role = UserRole::SuperAdmin;
                $user->must_change_password = true;
                $user->deactivated_at = null;
                $user->last_login_at = null;
                $user->save();
                $user->auditLogs()->create(['actor_role' => UserRole::SuperAdmin, 'action' => self::AUDIT_ACTION,
                    'module' => 'admin_accounts', 'target_type' => 'user', 'target_id' => $user->id]);
                $this->assertOwned($connection, $lock);
            });
        } finally {
            try {
                $this->release($connection, $lock);
            } catch (Throwable) {
                // Closing the session releases a named lock even if explicit release is unavailable.
                $connection->disconnect();
                Log::warning('Bootstrap lock release unavailable; database session closed.');
            }
        }
    }

    protected function acquire(Connection $connection, string $lock): bool
    {
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) {
            throw new RuntimeException('Bootstrap requires an idle MySQL primary connection.');
        }

        // Force primary: MySQL advisory locks belong to the physical connection, not a read replica or transaction.
        return (int) ($connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$lock], false)->acquired ?? 0) === 1;
    }

    protected function assertOwned(Connection $connection, string $lock): void
    {
        $row = $connection->selectOne('SELECT IS_USED_LOCK(?) AS owner, CONNECTION_ID() AS connection_id', [$lock], false);
        if (! $row || $row->owner === null || (string) $row->owner !== (string) $row->connection_id) {
            throw new RuntimeException('Bootstrap no longer owns its database lock.');
        }
    }

    protected function release(Connection $connection, string $lock): void
    {
        if ((int) ($connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock], false)->released ?? 0) !== 1) {
            throw new RuntimeException('Bootstrap lock could not be released.');
        }
    }
}
