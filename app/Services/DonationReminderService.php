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
            ->whereNotNull('joined_at')->whereNull('cancelled_at')->where('source_type', 'admin_announcement')
            ->whereHas('opportunity', fn (Builder $query) => $query->active()->whereDate('event_date', self::calendarNow()->addDay()->toDateString()));
    }

    public function sendDue(?User $user = null): int
    {
        $created = 0;
        $this->due()->when($user, fn (Builder $query) => $query->where('user_id', $user->id))
            ->select('donation_participations.id')->chunkById(100, function ($rows) use (&$created) {
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
        // Reuse the same deduplicated generator for donors joining after the hourly run.
        $this->sendDue($user);
        $reminders = [];
        $notices = $user->notifications()->where('type', 'donation_reminder')
            ->where('data->reminder_date', self::calendarNow()->toDateString())->orderByDesc('id')->get();
        foreach ($notices as $notice) {
            if (! $this->current($notice)) {
                continue;
            }
            $item = $this->due()->where('user_id', $user->id)->whereKey($notice->data['participation_id'])->with('opportunity')->first();
            if (! $item) {
                continue;
            }
            if (DB::table('donation_preparation_acknowledgements')->where('user_id', $user->id)
                ->where('donation_opportunity_id', $item->donation_opportunity_id)->exists()) {
                continue;
            }
            $reminders[] = $this->payload($notice, $item);
        }

        return ['reminders' => $reminders, 'server_time' => now()->toIso8601String()];
    }

    public function preparation(User $user, int $id, bool $acknowledge = false): array
    {
        return DB::transaction(function () use ($user, $id, $acknowledge) {
            $notice = $user->notifications()->where('type', 'donation_reminder')->findOrFail($id);
            $item = $user->donationParticipations()->whereKey($notice->data['participation_id'] ?? 0)->lockForUpdate()->first();
            abort_unless($item, 409, 'This preparation reminder is no longer current.');
            $item->opportunity()->lockForUpdate()->first();
            abort_unless($this->current($notice), 409, 'This preparation reminder is no longer current.');
            if ($acknowledge) {
                DB::table('donation_preparation_acknowledgements')->insertOrIgnore([
                    'user_id' => $user->id, 'donation_opportunity_id' => $item->donation_opportunity_id,
                    'acknowledged_at' => now(),
                ]);
            }

            return $this->payload($notice, $item->load('opportunity'));
        }, 3);
    }

    private function payload(Notification $notice, DonationParticipation $item): array
    {
        $deadline = min(self::calendarNow()->addDay()->startOfDay()->timestamp, $item->opportunity->expires_at->timestamp);

        return ['notification' => $notice, 'activity_title' => $item->opportunity->title,
            'event_date' => $item->opportunity->event_date->toDateString(), 'start_time' => $item->opportunity->start_time,
            'remaining_seconds' => max(0, $deadline - now()->timestamp)];
    }
}
