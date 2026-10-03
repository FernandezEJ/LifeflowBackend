<?php

namespace App\Console\Commands;

use App\Services\DonationCooldown;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('donations:notify-cooldown-complete')]
#[Description('Notify donors once when their latest completed donation rest period ends')]
class SendDonationCooldownNotifications extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DonationCooldown $cooldown): int
    {
        $this->info('Created '.$cooldown->sendDue().' donation rest period notification(s).');

        return self::SUCCESS;
    }
}
