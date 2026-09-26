<?php

namespace App\Console\Commands;

use App\Services\DonationReminderService;
use Illuminate\Console\Command;

class SendDonationReminders extends Command
{
    protected $signature = 'donations:send-reminders';

    protected $description = 'Create deduplicated reminders for active donations scheduled tomorrow';

    public function handle(DonationReminderService $reminders): int
    {
        $this->info('Created '.$reminders->sendDue().' donation reminder(s).');

        return self::SUCCESS;
    }
}
