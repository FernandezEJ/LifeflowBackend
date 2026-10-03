<?php

use App\Services\DonationCooldown;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hourly catch-up also covers donors who join later on the day before an event.
Schedule::command('donations:send-reminders')->hourly()->withoutOverlapping();
Schedule::command('donations:notify-cooldown-complete')->hourly()->timezone(DonationCooldown::TIMEZONE)->withoutOverlapping();
Schedule::command('admins:purge-deactivated')->daily()->timezone(config('app.calendar_timezone'))->withoutOverlapping();
Schedule::command('announcements:purge-deleted')->daily()->timezone(config('app.calendar_timezone'))->withoutOverlapping();
Schedule::command('rewards:purge-deleted')->daily()->timezone(config('app.calendar_timezone'))->withoutOverlapping();
Schedule::command('flowie:purge-deleted')->daily()->timezone(config('app.calendar_timezone'))->withoutOverlapping();
