<?php

namespace App\Console\Commands;

use App\Services\Admin\AnnouncementService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('announcements:purge-deleted')]
#[Description('Purge announcements deleted for 30 days, retaining participation history')]
class PurgeDeletedAnnouncements extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AnnouncementService $service): int
    {
        $counts = $service->purge();
        $this->info('Purged '.$counts['purged'].' announcement(s); retained '.$counts['retained'].' with participation history.');

        return self::SUCCESS;
    }
}
