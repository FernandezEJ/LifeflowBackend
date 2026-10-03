<?php

namespace App\Services;

use App\Models\DonationParticipation;
use App\Models\DonationRecord;
use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DonationCooldown
{
    public const TIMEZONE = 'Asia/Manila';

    /** Completed participations and trusted legacy records, without counting a linked record twice. */
    public function latest(User $user, bool $lock = false): DonationParticipation|DonationRecord|null
    {
        $query = $user->donationParticipations()->where('status', 'completed')
            ->orderByRaw('COALESCE(verified_at, updated_at, created_at) DESC')->orderByDesc('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $latest = $query->first();
        $legacy = $user->donationRecords()->where('status', 'completed')
            ->where(fn (Builder $query) => $query->whereNull('donation_participation_id')
                ->orWhereNotIn('donation_participation_id', $user->donationParticipations()->where('status', 'completed')->select('id')));
        if ($lock) {
            $legacy->lockForUpdate();
        }
        foreach ($legacy->get() as $record) {
            if ($latest === null || $this->completedAt($record)->greaterThan($this->completedAt($latest))) {
                $latest = $record;
            }
        }

        return $latest;
    }

    public function completedAt(DonationParticipation|DonationRecord $item): CarbonImmutable
    {
        if ($item->verified_at !== null) {
            return CarbonImmutable::instance($item->verified_at)->timezone(self::TIMEZONE);
        }
        if ($item instanceof DonationRecord && $item->donation_date !== null) {
            return CarbonImmutable::parse($item->donation_date->toDateString(), self::TIMEZONE);
        }

        // Older trusted participations lack verified_at; preserve history and use their saved timestamp.
        return CarbonImmutable::instance($item->updated_at ?? $item->created_at)->timezone(self::TIMEZONE);
    }

    public function nextEligibleAt(DonationParticipation|DonationRecord $item): CarbonImmutable
    {
        return $this->completedAt($item)->addMonthsNoOverflow(3);
    }

    public function metadata(User $user, bool $lock = false): array
    {
        return $this->forCompletion($this->latest($user, $lock));
    }

    private function forCompletion(DonationParticipation|DonationRecord|null $item): array
    {
        $next = $item ? $this->nextEligibleAt($item) : null;
        $remaining = $next ? max(0, (int) ceil(CarbonImmutable::now(self::TIMEZONE)->diffInSeconds($next, false))) : 0;

        return ['is_on_donation_cooldown' => $remaining > 0,
            'last_completed_donation_at' => $item ? $this->completedAt($item)->toIso8601String() : null,
            'next_eligible_donation_at' => $next?->toIso8601String(),
            'donation_cooldown_remaining_seconds' => $remaining];
    }

    public function eventKey(DonationParticipation|DonationRecord $item): string
    {
        return ($item instanceof DonationRecord ? 'donation-record:' : 'participation:').$item->id.':donation_cooldown_complete';
    }

    /** Recheck the latest cycle under the same donor lock used by join and completion. */
    public function sendDue(): int
    {
        $created = 0;
        User::query()->where('role', 'donor')->where(function (Builder $query): void {
            $query->whereHas('donationParticipations', fn (Builder $q) => $q->where('status', 'completed'))
                ->orWhereHas('donationRecords', fn (Builder $q) => $q->where('status', 'completed'));
        })->select('id')->chunkById(100, function ($users) use (&$created): void {
            foreach ($users as $user) {
                DB::transaction(function () use ($user, &$created): void {
                    $donor = User::whereKey($user->id)->lockForUpdate()->first();
                    if ($donor === null) {
                        return;
                    }
                    $latest = $this->latest($donor, true);
                    if ($latest === null || $this->forCompletion($latest)['is_on_donation_cooldown']) {
                        return;
                    }
                    $notice = app(NotificationService::class)->notifyDonationCooldownComplete($donor, $latest, $this->forCompletion($latest), $this->eventKey($latest));
                    $created += (int) $notice->wasRecentlyCreated;
                }, 3);
            }
        });

        return $created;
    }

    /** Suppress a delayed push if a newer completed donation has started another cycle. */
    public function currentNotification(Notification $notification): bool
    {
        $latest = $this->latest($notification->user);

        return $latest !== null && $this->eventKey($latest) === $notification->getRawOriginal('event_key')
            && ! $this->forCompletion($latest)['is_on_donation_cooldown'];
    }
}
