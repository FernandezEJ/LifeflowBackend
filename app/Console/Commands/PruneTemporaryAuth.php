<?php

namespace App\Console\Commands;

use App\Services\TemporaryAuthRetention;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('auth:prune-temporary {--dry-run : Count eligible rows without deleting or changing scan cursors}')]
#[Description('Prune only temporary authentication records expired for at least 24 hours')]
class PruneTemporaryAuth extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TemporaryAuthRetention $retention): int
    {
        try {
            $result = $retention->run((bool) $this->option('dry-run'));
            if ($result['busy']) {
                $this->info('Temporary authentication retention is already running.');
            }
            foreach ($result['tables'] as $table => $counts) {
                $this->info($table.': scanned='.$counts['scanned'].' eligible='.$counts['eligible'].' deleted='.$counts['deleted']);
            }

            return self::SUCCESS;
        } catch (Throwable) {
            // Never log SQL, exception chains, challenge hashes or encrypted payloads.
            Log::error('Temporary authentication retention failed; earlier row transactions may have committed.');
            $this->error('Temporary authentication retention failed. Check database/cache availability and retry safely.');

            return self::FAILURE;
        }
    }
}
