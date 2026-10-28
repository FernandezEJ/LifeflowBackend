<?php

namespace App\Services;

use App\Jobs\SendDevicePush;
use App\Jobs\SendImportantPush;
use App\Models\DeviceToken;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PushDeliveryService
{
    public const TABLE = 'notification_push_deliveries';

    public const PAGE_SIZE = 50;

    public function current(Notification $notice): bool
    {
        $created = SessionTokenPolicy::timestamp($notice->getRawOriginal('created_at'));

        return in_array($notice->type, Notification::TYPES, true) && $created && $created->lte(now()) && $created->addDay()->gt(now())
            && $notice->user?->isActiveVerifiedDonor()
            && ($notice->type !== 'donation_reminder' || app(DonationReminderService::class)->current($notice))
            && ($notice->type !== 'donation_cooldown_complete' || app(DonationCooldown::class)->currentNotification($notice));
    }

    /** One bounded fan-out page and at most one inline per-device attempt preserve the existing job boundary. */
    public function fanOut(Notification $notice, PushNotificationService $transport): void
    {
        if (! in_array($notice->type, Notification::TYPES, true)) {
            return;
        }
        $notice = $notice->fresh();
        if (! $notice || ($notice->push_delivery_version === 0 && $notice->push_attempted_at !== null)) {
            return; // Legacy attempts have unknowable recipient results; never reconstruct or replay.
        }
        if (! $this->current($notice)) {
            $notice->update(['push_status' => 'not_due', 'push_delivery_version' => 1, 'push_fanout_completed_at' => now()]);

            return;
        }
        if (! $transport->configured()) {
            if ($notice->push_delivery_version === 0) {
                $notice->update(['push_status' => 'not_configured']);
            }

            return;
        }
        $ids = DB::transaction(function () use ($notice): array {
            $locked = Notification::whereKey($notice->id)->lockForUpdate()->first();
            if (! $locked || $locked->push_fanout_completed_at || ($locked->push_delivery_version === 0 && $locked->push_attempted_at)) {
                return [];
            }
            $max = $locked->push_fanout_max_device_id ?? (DeviceToken::where('user_id', $locked->user_id)->max('id') ?? 0);
            $devices = DeviceToken::where('user_id', $locked->user_id)->where('provider', 'fcm')->where('platform', 'android')
                ->where('id', '>', $locked->push_fanout_cursor)->where('id', '<=', $max)
                ->where('last_seen_at', '>=', now()->subDays(30))->orderBy('id')->limit(self::PAGE_SIZE)
                ->get(['id', 'personal_access_token_id', 'token_hash']);
            $ids = [];
            foreach ($devices as $device) {
                $target = ['notification_id' => $locked->id, 'device_token_id' => $device->id,
                    'personal_access_token_id' => $device->personal_access_token_id, 'token_hash' => $device->token_hash];
                DB::table(self::TABLE)->insertOrIgnore([...$target, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                $ids[] = DB::table(self::TABLE)->where($target)->value('id');
            }
            $cursor = $devices->last()?->id ?? $max;
            $locked->update(['push_delivery_version' => 1, 'push_attempted_at' => $locked->push_attempted_at ?? now(),
                'push_status' => 'pending', 'push_fanout_cursor' => $cursor, 'push_fanout_max_device_id' => $max,
                'push_fanout_completed_at' => $devices->count() < self::PAGE_SIZE || $cursor >= $max ? now() : null]);

            return $ids;
        }, 3);
        foreach ($ids as $index => $id) {
            if ($index === 0) {
                $this->deliver($id, $transport);
            } else {
                $this->dispatchDevice($id);
            }
        }
        if ($notice->fresh()?->push_fanout_completed_at === null) {
            $this->dispatchFanOut($notice->id);
        }
        $this->summarize($notice->id);
    }

    /** OAuth/preflight failures leave pending intact; after the durable claim nothing is automatically replayable. */
    public function deliver(int $id, PushNotificationService $transport): void
    {
        $row = DB::table(self::TABLE)->where('id', $id)->where('state', 'pending')->first();
        if (! $row) {
            return;
        }
        $notice = Notification::find($row->notification_id);
        if (! $notice || ! $this->current($notice)) {
            $this->finish($id, 'pending', 'skipped', 'not_due');

            return;
        }
        if (! $transport->configured()) {
            return;
        }
        try {
            $bearer = $transport->authorization();
        } catch (Throwable) {
            Log::warning('Push preflight unavailable; unattempted delivery remains pending.');

            return;
        }
        try {
            $device = DB::transaction(function () use ($row, $notice): ?DeviceToken {
                $owner = User::whereKey($notice->user_id)->lockForUpdate()->first();
                $device = DeviceToken::whereKey($row->device_token_id)->lockForUpdate()->first();
                $target = DB::table(self::TABLE)->where('id', $row->id)->where('state', 'pending')->lockForUpdate()->first();
                if (! $target) {
                    return null;
                }
                $session = $device?->accessToken()->first();
                if (! $owner?->isActiveVerifiedDonor() || ! $this->current($notice->fresh()) || ! $device
                    || $device->user_id !== $notice->user_id || $device->personal_access_token_id !== $row->personal_access_token_id
                    || ! hash_equals($row->token_hash, $device->token_hash) || $device->provider !== 'fcm' || $device->platform !== 'android'
                    || $device->last_seen_at->lt(now()->subDays(30)) || ! $session || ! SessionTokenPolicy::allows($session, $owner)) {
                    DB::table(self::TABLE)->where('id', $row->id)->where('state', 'pending')->update([
                        'state' => 'skipped', 'failure_code' => 'ineligible', 'completed_at' => now(), 'updated_at' => now(),
                    ]);

                    return null;
                }
                DB::table(self::TABLE)->where('id', $row->id)->where('state', 'pending')->update([
                    'state' => 'attempting', 'attempted_at' => now(), 'updated_at' => now(),
                ]);

                return $device;
            }, 3);
            if (! $device) {
                $this->summarize($notice->id);

                return;
            }
            $response = $transport->sendToDevice($notice, $device, $bearer);
            if ($response->successful()) {
                $accepted = is_string($response->json('name')) && trim($response->json('name')) !== '';
                $this->finish($id, 'attempting', $accepted ? 'accepted' : 'ambiguous', $accepted ? null : 'invalid_response');
            } else {
                $this->finish($id, 'attempting', 'rejected', 'provider_rejected');
                if (collect($response->json('error.details', []))->pluck('errorCode')->contains('UNREGISTERED')) {
                    DeviceToken::whereKey($device->id)->where('user_id', $notice->user_id)->where('token_hash', $row->token_hash)
                        ->where('personal_access_token_id', $row->personal_access_token_id)->delete();
                }
            }
        } catch (Throwable) {
            // Includes uncertain network sends and result persistence failures. Never throw/log provider material.
            try {
                $this->finish($id, 'attempting', 'ambiguous', 'send_outcome_unknown');
            } catch (Throwable) {
                Log::error('Push outcome persistence unavailable; attempted delivery must not be replayed.');
            }
        }
    }

    public function interrupted(int $id): void
    {
        try {
            $this->finish($id, 'attempting', 'ambiguous', 'worker_interrupted');
        } catch (Throwable) {
            Log::error('Push interruption bookkeeping unavailable; monitor attempted deliveries.');
        }
    }

    private function finish(int $id, string $from, string $state, ?string $reason): void
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first(['notification_id']);
        $changed = DB::table(self::TABLE)->where('id', $id)->where('state', $from)->update([
            'state' => $state, 'failure_code' => $reason, 'completed_at' => now(), 'updated_at' => now(),
        ]);
        if ($changed && in_array($state, ['ambiguous', 'rejected'], true)) {
            Log::warning('Push delivery requires monitoring.', ['state' => $state]);
        }
        if ($row) {
            $this->summarize($row->notification_id);
        }
    }

    private function dispatchDevice(int $id): bool
    {
        $job = (new SendDevicePush($id))->onConnection('database');
        $unique = app(UniqueLock::class);
        if (! $unique->acquire($job)) {
            return false;
        }
        try {
            // Acquire explicitly so suppressed jobs are not counted, and an outage releases the reservation.
            Bus::dispatch($job);

            return true;
        } catch (Throwable) {
            $unique->release($job);
            Log::warning('Unattempted push dispatch unavailable; bounded recovery can resume it.');

            return false;
        }
    }

    private function dispatchFanOut(int $id): bool
    {
        try {
            SendImportantPush::dispatch($id)->onConnection('database');

            return true;
        } catch (Throwable) {
            Log::warning('Push fan-out dispatch unavailable; bounded recovery can resume it.');

            return false;
        }
    }

    public function summarize(int $notificationId): void
    {
        DB::transaction(function () use ($notificationId): void {
            $notice = Notification::whereKey($notificationId)->lockForUpdate()->first();
            if (! $notice || $notice->push_delivery_version !== 1) {
                return;
            }
            $counts = DB::table(self::TABLE)->where('notification_id', $notificationId)->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state');
            $status = ! $notice->push_fanout_completed_at || ($counts['pending'] ?? 0) > 0 ? 'pending'
                : (($counts['attempting'] ?? 0) > 0 ? 'sending'
                    : (($counts['ambiguous'] ?? 0) > 0 ? 'ambiguous'
                        : (($counts['rejected'] ?? 0) > 0 ? (($counts['accepted'] ?? 0) > 0 ? 'partial' : 'failed')
                            : (($counts['accepted'] ?? 0) > 0 ? 'sent' : 'no_devices'))));
            $notice->update(['push_status' => $status]);
        }, 3);
    }

    /** No network calls here. Only confirmed-pending work is dispatched; stale attempts become ambiguous. */
    public function recover(PushNotificationService $transport): array
    {
        $counts = ['ambiguous' => 0, 'legacy_ambiguous' => 0, 'fanout_jobs' => 0, 'device_jobs' => 0];
        foreach (Notification::where('push_delivery_version', 0)->whereNull('push_fanout_completed_at')
            ->where('push_status', 'sending')->where('push_attempted_at', '<=', now()->subMinutes(5))->orderBy('id')->limit(100)->pluck('id') as $id) {
            $counts['legacy_ambiguous'] += Notification::whereKey($id)->where('push_delivery_version', 0)->where('push_status', 'sending')
                ->where('push_attempted_at', '<=', now()->subMinutes(5))->update(['push_status' => 'ambiguous']);
        }
        foreach (DB::table(self::TABLE)->where('state', 'attempting')->where('attempted_at', '<=', now()->subMinutes(5))->orderBy('attempted_at')->orderBy('id')->limit(100)->pluck('id') as $id) {
            $this->finish($id, 'attempting', 'ambiguous', 'worker_interrupted');
            $counts['ambiguous']++;
        }
        if ($transport->configured()) {
            $unfinished = Notification::whereNull('push_fanout_completed_at')->where(function ($query): void {
                $query->where('push_delivery_version', 1)->orWhere(fn ($query) => $query->where('push_delivery_version', 0)->whereNull('push_attempted_at'));
            })->orderBy('id')->limit(50)->pluck('id');
            foreach ($unfinished as $id) {
                $counts['fanout_jobs'] += $this->dispatchFanOut($id) ? 1 : 0;
            }
            foreach (DB::table(self::TABLE)->where('state', 'pending')->orderBy('id')->limit(100 - $unfinished->count())->pluck('id') as $id) {
                $counts['device_jobs'] += $this->dispatchDevice($id) ? 1 : 0;
            }
        }
        Log::info('Push delivery recovery completed.', $counts);

        return $counts;
    }
}
