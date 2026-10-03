<?php

namespace App\Console\Commands;

use App\Services\Admin\AdminManagementService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('admins:purge-deactivated')]
#[Description('Purge Admins deactivated for 30 days; retain accounts owning historical records')]
class PurgeDeactivatedAdmins extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AdminManagementService $service): int
    {
        $counts = $service->purge();
        $this->info('Purged '.$counts['purged'].' Admin(s); retained '.$counts['retained'].' with historical records for review.');

        return self::SUCCESS;
    }
}
