<?php

namespace App\Services;

use App\Jobs\SendDonorLoginCode;
use App\Mail\DonorLoginCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DonorLoginService
{
    public const NOTICE = 'If the account is eligible for passwordless login, a verification code has been sent.';

    public const EXPIRY_SECONDS = 600;

    public const RESEND_SECONDS = 60;

    /** Queue known and unknown numbers identically; no account lookup or SMTP on the public request path. */
    public function request(string $mobile): array
    {
        $key = hash('sha256', $mobile);
        if (! Cache::add('donor-login:'.$key, true, self::RESEND_SECONDS)) {
            abort(429, 'Please wait 60 seconds before requesting another login code.');
        }
        $id = (string) Str::uuid();
        DB::transaction(function () use ($key, $id): void {
            $old = DB::table('donor_login_codes')->where('mobile_hash', $key)->lockForUpdate()->first();
            if ($old && now()->lessThan($old->resend_at)) {
                abort(429, 'Please wait 60 seconds before requesting another login code.');
            }
            DB::table('donor_login_codes')->upsert([[
                'mobile_hash' => $key, 'request_id' => $id, 'purpose' => 'donor_login',
                'user_id' => null, 'email_hash' => null, 'code_hash' => null,
                'expires_at' => now()->addSeconds(self::EXPIRY_SECONDS), 'resend_at' => now()->addSeconds(self::RESEND_SECONDS),
                'attempt_count' => 0, 'used_at' => null, 'delivery_claimed_at' => null,
                'confirmed_token_id' => null, 'confirmed_at' => null, 'confirmation_used_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]], ['mobile_hash']);
        }, 3);
        try {
            SendDonorLoginCode::dispatch($id, $mobile)->onConnection('database')->onQueue('donor-logins')->afterCommit();
        } catch (Throwable) {
            $this->invalidateDelivery($id);
            Log::error('Donor login code could not be queued. Check queue configuration.');
        }

        return ['message' => self::NOTICE, 'resend_after' => self::RESEND_SECONDS, 'expires_in' => self::EXPIRY_SECONDS];
    }

    /** Refuse ambiguous legacy mappings, non-donor roles, deleted accounts, and deactivation. */
    private function donor(string $mobile): ?User
    {
        $matches = User::query()->whereHas('donorProfile', fn ($query) => $query->whereIn('mobile_number', DonorIdentity::variants($mobile)))->limit(2)->get();
        $user = $matches->count() === 1 ? $matches->first() : null;

        return $user?->isDonor() && ! $user->isDeactivated() ? $user : null;
    }

    private function secret(#[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', 'donor_login:'.$code, (string) config('app.key'));
    }

    /** Generate the random code in the worker; queued payloads contain no plaintext OTP. */
    public function deliver(string $id, string $mobile): void
    {
        try {
            $candidate = $this->donor($mobile);
            if (! $candidate) {
                return;
            }
            $delivery = DB::transaction(function () use ($candidate, $id, $mobile): ?array {
                $user = User::whereKey($candidate->id)->lockForUpdate()->first();
                $row = DB::table('donor_login_codes')->where('request_id', $id)->lockForUpdate()->first();
                if (! $user || ! $user->isDonor() || $user->isDeactivated() || $this->donor($mobile)?->id !== $user->id
                    || ! $row || $row->mobile_hash !== hash('sha256', $mobile) || $row->used_at || $row->delivery_claimed_at
                    || now()->greaterThanOrEqualTo($row->expires_at)) {
                    return null;
                }
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                DB::table('donor_login_codes')->where('request_id', $id)->update([
                    'user_id' => $user->id, 'email_hash' => hash('sha256', $user->email),
                    'code_hash' => Hash::make($this->secret($code)), 'delivery_claimed_at' => now(), 'updated_at' => now(),
                ]);

                return [$user->email, $code];
            }, 3);
            if (! $delivery) {
                return;
            }
            // Reuse the app's TLS-only transport; never send login codes to log/failover mailers.
            $smtp = config('mail.mailers.password_reset', []);
            if (config('mail.default') !== 'smtp' || ($smtp['transport'] ?? '') !== 'smtp'
                || empty($smtp['host']) || empty($smtp['username']) || empty($smtp['password']) || empty(config('mail.from.address'))) {
                $this->invalidateDelivery($id);
                Log::error('Donor login SMTP is not configured. Check mail settings.');

                return;
            }
            Mail::mailer('password_reset')->to($delivery[0])->send(new DonorLoginCodeMail($delivery[1]));
        } catch (Throwable) {
            $this->invalidateDelivery($id);
            Log::error('Donor login email delivery failed. Check SMTP connectivity and configuration.');
        }
    }

    public function invalidateDelivery(string $id): void
    {
        try {
            DB::table('donor_login_codes')->where('request_id', $id)->update(['code_hash' => null, 'delivery_claimed_at' => now(), 'updated_at' => now()]);
        } catch (Throwable) {
            Log::error('Donor login delivery cleanup failed. Check database availability.');
        }
    }

    /** Consume once under donor-first locks; invalid attempt increments commit before returning the error. */
    public function verify(string $mobile, #[\SensitiveParameter] string $code, ?User $confirmFor = null): array
    {
        $candidate = $this->donor($mobile);
        $result = $candidate ? DB::transaction(function () use ($candidate, $mobile, $code, $confirmFor): ?array {
            $user = User::whereKey($candidate->id)->lockForUpdate()->first();
            $row = DB::table('donor_login_codes')->where('mobile_hash', hash('sha256', $mobile))->lockForUpdate()->first();
            if (! $user || ! $user->isDonor() || $user->isDeactivated() || $this->donor($mobile)?->id !== $user->id
                || ! $row || $row->purpose !== 'donor_login' || $row->user_id !== $user->id || $row->used_at || ! $row->code_hash
                || $row->email_hash !== hash('sha256', $user->email) || $row->attempt_count >= 5 || now()->greaterThanOrEqualTo($row->expires_at)) {
                return null;
            }
            if ($confirmFor && ($confirmFor->id !== $user->id || ! $user->tokens()->whereKey($confirmFor->currentAccessToken()?->getKey())->exists())) {
                return null;
            }
            if (! Hash::check($this->secret($code), $row->code_hash)) {
                DB::table('donor_login_codes')->where('mobile_hash', $row->mobile_hash)->update(['attempt_count' => $row->attempt_count + 1, 'updated_at' => now()]);

                return null;
            }
            DB::table('donor_login_codes')->where('mobile_hash', $row->mobile_hash)->update([
                'used_at' => now(), 'code_hash' => null, 'updated_at' => now(),
                'confirmed_token_id' => $confirmFor?->currentAccessToken()?->getKey(), 'confirmed_at' => $confirmFor ? now() : null,
            ]);
            if ($confirmFor) {
                return ['message' => 'Current email confirmed.'];
            }

            return ['message' => 'Login successful', 'user' => $user, 'donor_profile' => $user->donorProfile,
                'token' => $user->createToken('mobile')->plainTextToken];
        }, 3) : null;
        if ($result === null) {
            throw ValidationException::withMessages(['code' => 'The login code is invalid, expired, or already used. Request a new code if needed.']);
        }

        return $result;
    }

    /** Called within the existing email-change transaction, bound to this donor, email, and bearer token. */
    public function consumeEmailConfirmation(User $actor): void
    {
        $row = DB::table('donor_login_codes')->where('user_id', $actor->id)
            ->where('confirmed_token_id', $actor->currentAccessToken()?->getKey())->lockForUpdate()->first();
        if (! $actor->isDonor() || ! $row || ! $row->confirmed_at || $row->confirmation_used_at
            || $row->email_hash !== hash('sha256', $actor->email) || now()->subMinutes(10)->greaterThanOrEqualTo($row->confirmed_at)) {
            throw ValidationException::withMessages(['code' => 'Confirm your current email with a new login code before changing your email.']);
        }
        DB::table('donor_login_codes')->where('mobile_hash', $row->mobile_hash)->update(['confirmation_used_at' => now(), 'updated_at' => now()]);
    }
}
