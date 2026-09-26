<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hourly catch-up also covers donors who join later on the day before an event.
Schedule::command('donations:send-reminders')->hourly()->withoutOverlapping();
