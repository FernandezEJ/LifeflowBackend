<?php

namespace App\Console\Commands;

use App\Services\Admin\RewardManagementService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rewards:purge-deleted')]
#[Description('Purge unused rewards after 30 days; retain all voucher and ledger history')]
class PurgeDeletedRewards extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RewardManagementService $service): int
    {
        $counts = $service->purge();
        $this->info('Purged '.$counts['purged'].' reward(s); retained '.$counts['retained'].' with voucher history.');

        return self::SUCCESS;
    }
}
