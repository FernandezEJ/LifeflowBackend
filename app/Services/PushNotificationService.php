<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class PushNotificationService
{
    // ========================================
    // FCM HTTP V1 DELIVERY BOUNDARY
    // Missing credentials leave in-app history usable. Atomically claiming
    // each notification prevents replayed jobs from spamming devices.
    // ========================================
    public function send(Notification $notification): void
    {
        if (! in_array($notification->type, Notification::TYPES, true)) {
            return;
        }
        if ($notification->type === 'donation_reminder' && ! app(DonationReminderService::class)->current($notification)) {
            $notification->update(['push_status' => 'not_due']);

            return;
        }
        if (! config('services.fcm.enabled') || ! config('services.fcm.project_id') || ! is_file((string) config('services.fcm.credentials'))) {
            $notification->update(['push_status' => 'not_configured']);

            return;
        }
        $claimed = Notification::whereKey($notification->id)->whereNull('push_attempted_at')
            ->update(['push_attempted_at' => now(), 'push_status' => 'sending']);
        if (! $claimed) {
            return;
        }
        try {
            $bearer = $this->accessToken();
            $failed = false;
            $sent = false;
            $devices = $notification->user->deviceTokens()
                ->where('provider', 'fcm')->where('platform', 'android')
                ->where('last_seen_at', '>=', now()->subDays(30))
                ->whereHas('accessToken', function ($query) {
                    $query->where(function ($query) {
                        $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    });
                    if (config('sanctum.expiration')) {
                        $query->where('created_at', '>', now()->subMinutes(config('sanctum.expiration')));
                    }
                })->get();
            foreach ($devices as $device) {
                // Recheck ownership/session just before sending, including account
                // transfer and logout that occurred while this job was queued.
                if (! DeviceToken::whereKey($device->id)->where('user_id', $notification->user_id)
                    ->where('personal_access_token_id', $device->personal_access_token_id)->exists()) {
                    continue;
                }
                try {
                    $data = array_map(fn ($value): string => (string) $value, $notification->data ?? []);
                    $response = Http::withToken($bearer)->acceptJson()->timeout(10)->connectTimeout(5)
                        ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode(config('services.fcm.project_id')).'/messages:send', [
                            'message' => [
                                'token' => $device->token,
                                'notification' => ['title' => $notification->title, 'body' => in_array($notification->type, ['donation_rejected', 'donation_needs_revision'], true)
                                    ? 'Please open your activity to review your donation proof.' : $notification->message],
                                'data' => [...$data, 'type' => $notification->type, 'notification_id' => (string) $notification->id,
                                    // Expo Android reads custom navigation data from the JSON body field.
                                    'body' => json_encode([...$data, 'type' => $notification->type, 'notification_id' => (string) $notification->id])],
                                'android' => ['priority' => 'normal', 'ttl' => '86400s',
                                    'notification' => ['channel_id' => 'important', 'tag' => 'lifeflow-'.$notification->id]],
                            ],
                        ]);
                    $codes = collect($response->json('error.details', []))->pluck('errorCode');
                    if ($codes->contains('UNREGISTERED')) {
                        DeviceToken::whereKey($device->id)->where('token_hash', $device->token_hash)
                            ->where('personal_access_token_id', $device->personal_access_token_id)->delete();
                    }
                    $failed = $failed || ! $response->successful();
                    $sent = $sent || $response->successful();
                } catch (Throwable) {
                    $failed = true;
                }
            }
            $notification->update(['push_status' => $failed ? 'failed' : ($sent ? 'sent' : 'no_devices')]);
        } catch (Throwable) {
            // Do not persist provider payloads or log tokens/private keys.
            $notification->update(['push_status' => 'failed']);
        }
    }

    // ========================================
    // SHORT-LIVED SERVER OAUTH TOKEN
    // Read the private service account outside the public/frontend tree.
    // Fixed Google endpoints prevent credential files from redirecting secrets.
    // ========================================
    protected function accessToken(): string
    {
        $path = (string) config('services.fcm.credentials');
        $key = 'lifeflow-fcm-oauth:'.hash('sha256', $path.':'.filemtime($path));

        return Cache::remember($key, 3000, function () use ($path): string {
            $account = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (($account['project_id'] ?? '') !== config('services.fcm.project_id')
                || empty($account['client_email']) || empty($account['private_key'])) {
                throw new RuntimeException('FCM service account configuration is incomplete.');
            }
            $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            $now = time();
            $jwt = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode(json_encode([
                'iss' => $account['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
            ]));
            if (! openssl_sign($jwt, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('FCM service account signing failed.');
            }
            $response = Http::asForm()->timeout(10)->connectTimeout(5)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt.'.'.$encode($signature),
            ]);
            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new RuntimeException('FCM authorization failed.');
            }

            return $response->json('access_token');
        });
    }
}
