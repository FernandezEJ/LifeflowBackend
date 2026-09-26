<?php

namespace App\Services;

use App\Models\DonationParticipation;
use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DonationReminderService
{
    public static function calendarNow(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.calendar_timezone'));
    }

    public function due(): Builder
    {
        return DonationParticipation::query()->whereIn('status', ['pending', 'for_verification', 'needs_revision'])
            ->whereHas('opportunity', fn (Builder $query) => $query->active()->whereDate('event_date', self::calendarNow()->addDay()->toDateString()));
    }

    public function sendDue(): int
    {
        $created = 0;
        $this->due()->select('donation_participations.id')->chunkById(100, function ($rows) use (&$created) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, &$created) {
                    $item = DonationParticipation::whereKey($row->id)->lockForUpdate()->first();
                    if (! $item) {
                        return;
                    }
                    $item->opportunity()->lockForUpdate()->first();
                    if (! $this->due()->whereKey($item->id)->exists()) {
                        return;
                    }
                    $notice = app(NotificationService::class)->notifyDonationReminder($item);
                    $created += (int) $notice->wasRecentlyCreated;
                });
            }
        });

        return $created;
    }

    public function current(Notification $notice): bool
    {
        return ($notice->data['reminder_date'] ?? null) === self::calendarNow()->toDateString()
            && ($notice->data['event_date'] ?? null) === self::calendarNow()->addDay()->toDateString()
            && $this->due()->where('user_id', $notice->user_id)->whereKey($notice->data['participation_id'] ?? 0)->exists();
    }

    public function forHome(User $user): array
    {
        $reminders = [];
        $notices = $user->notifications()->where('type', 'donation_reminder')->whereNull('read_at')
            ->where('data->reminder_date', self::calendarNow()->toDateString())->orderByDesc('id')->get();
        foreach ($notices as $notice) {
            if (! $this->current($notice)) {
                continue;
            }
            $item = $this->due()->where('user_id', $user->id)->whereKey($notice->data['participation_id'])->with('opportunity')->first();
            if (! $item) {
                continue;
            }
            $deadline = min(self::calendarNow()->addDay()->startOfDay()->timestamp, $item->opportunity->expires_at->timestamp);
            $reminders[] = ['notification' => $notice, 'activity_title' => $item->opportunity->title,
                'event_date' => $item->opportunity->event_date->toDateString(), 'start_time' => $item->opportunity->start_time,
                'remaining_seconds' => max(0, $deadline - now()->timestamp)];
        }

        return ['reminders' => $reminders, 'server_time' => now()->toIso8601String()];
    }
}
