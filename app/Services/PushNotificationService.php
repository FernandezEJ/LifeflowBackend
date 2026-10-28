<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\Notification;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PushNotificationService
{
    // ========================================
    // FCM HTTP V1 DELIVERY BOUNDARY
    // Missing credentials leave in-app history usable. Per-device durable
    // progress prevents accepted or ambiguous sends from being replayed.
    // ========================================
    public function send(Notification $notification): void
    {
        app(PushDeliveryService::class)->fanOut($notification, $this);
    }

    public function configured(): bool
    {
        return (bool) config('services.fcm.enabled') && (bool) config('services.fcm.project_id')
            && is_file((string) config('services.fcm.credentials'));
    }

    public function authorization(): string
    {
        return $this->accessToken();
    }

    /** The existing low-sensitivity FCM payload; one bounded send, no HTTP retry. */
    public function sendToDevice(Notification $notification, DeviceToken $device, string $bearer): Response
    {
        $data = array_map(fn ($value): string => (string) $value, $notification->data ?? []);

        return Http::withToken($bearer)->withoutRedirecting()->acceptJson()->timeout(10)->connectTimeout(5)
            ->post('https://fcm.googleapis.com/v1/projects/'.rawurlencode(config('services.fcm.project_id')).'/messages:send', [
                'message' => [
                    'token' => $device->token,
                    'notification' => ['title' => $notification->title, 'body' => match ($notification->type) {
                        'donation_rejected' => 'Your donation proof was rejected; check the reason.',
                        'donation_needs_revision' => 'Please update your donation proof.',
                        default => $notification->message,
                    }],
                    'data' => [...$data, 'type' => $notification->type, 'notification_id' => (string) $notification->id,
                        // Expo Android reads custom navigation data from the JSON body field.
                        'body' => json_encode([...$data, 'type' => $notification->type, 'notification_id' => (string) $notification->id])],
                    'android' => ['priority' => 'normal', 'ttl' => '86400s',
                        'notification' => ['channel_id' => 'important', 'tag' => 'lifeflow-'.$notification->id]],
                ],
            ]);
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
