<?php

namespace App\Console\Commands;

use App\Services\FirstSuperAdminBootstrap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('admins:bootstrap-first-super-admin')]
#[Description('Owner-authorized one-time production staff bootstrap through a private interactive terminal')]
class BootstrapFirstSuperAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(FirstSuperAdminBootstrap $bootstrap): int
    {
        if (! app()->environment('production') || ! $this->input->isInteractive() || ! $this->privateTerminal()) {
            $this->error('Bootstrap requires production and a private interactive terminal.');

            return self::FAILURE;
        }
        try {
            // Never accept secrets through arguments, environment variables or a visible prompt fallback.
            $name = $this->secret('Staff name', false);
            $email = $this->secret('Staff email', false);
            $password = $this->secret('Initial password', false);
            $confirmation = $this->secret('Confirm initial password', false);
            if (! is_string($name) || ! is_string($email) || ! is_string($password) || ! is_string($confirmation)
                || ! hash_equals($password, $confirmation)) {
                $this->error('Bootstrap input was unavailable or passwords did not match. No account was created.');

                return self::FAILURE;
            }
            $bootstrap->create($name, $email, $password);
            $this->info('First Super Admin created. Sign in with the supplied credentials and change the initial password.');

            return self::SUCCESS;
        } catch (Throwable) {
            Log::error('First staff bootstrap did not complete normally. Inspect approved account/audit state before retrying.');
            $this->error('Bootstrap did not complete normally. Do not bypass state guards; use the approved runbook.');

            return self::FAILURE;
        } finally {
            unset($password, $confirmation);
        }
    }

    protected function privateTerminal(): bool
    {
        return defined('STDIN') && stream_isatty(STDIN);
    }
}
