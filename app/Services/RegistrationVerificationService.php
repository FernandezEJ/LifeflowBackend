<?php

namespace App\Services;

use App\Jobs\SendRegistrationVerificationCode;
use App\Mail\RegistrationVerificationMail;
use App\Models\DonorProfile;
use App\Models\User;
use App\Rules\AdultDonor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegistrationVerificationService
{
    public const TERMS_VERSION = '1.0';

    public const PRIVACY_VERSION = '1.0';

    public const CONSENT_FIELDS = ['accepted_terms', 'acknowledged_privacy', 'acknowledged_prescreening'];

    /** Require explicit booleans; stored legacy pending registrations cannot bypass consent. */
    private function requireConsent(array $data, bool $pending = true): void
    {
        foreach (self::CONSENT_FIELDS as $field) {
            if (($data[$field] ?? null) !== true) {
                throw ValidationException::withMessages([$pending ? 'pending_token' : $field => 'Return to registration and accept the required Terms, Privacy and pre-screening acknowledgements.']);
            }
        }
        if ($pending && (($data['terms_version'] ?? null) !== self::TERMS_VERSION || ($data['privacy_version'] ?? null) !== self::PRIVACY_VERSION)) {
            throw ValidationException::withMessages(['pending_token' => 'Please review the current Terms and Privacy Policy in registration.']);
        }
    }

    /** Store only encrypted donor details and a password hash until email ownership is proved. */
    public function request(array $data): array
    {
        $this->requireConsent($data, false);
        $data['terms_version'] = self::TERMS_VERSION;
        $data['privacy_version'] = self::PRIVACY_VERSION;
        $emailHash = hash('sha256', $data['email']);
        if (! Cache::add('registration-verification:'.$emailHash, true, 60)) {
            abort(429, 'Please wait before requesting another verification code.');
        }
        $token = bin2hex(random_bytes(32));
        $requestId = (string) Str::uuid();
        DB::table('pending_registrations')->upsert([[
            'email_hash' => $emailHash, 'pending_token_hash' => hash('sha256', $token),
            'request_id' => $requestId, 'payload' => Crypt::encryptString(json_encode(Arr::except($data, ['password', 'password_confirmation', 'accepted_at']))),
            // The shared password column remains valid; new donors never need to know this secret.
            'password_hash' => Hash::make($data['password'] ?? Str::random(64)), 'code_hash' => null,
            'expires_at' => now()->addMinutes(15), 'resend_at' => now()->addSeconds(60),
            'attempt_count' => 0, 'delivery_claimed_at' => null, 'verified_at' => null, 'used_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]], ['email_hash']);
        $this->queue($requestId);

        return ['pending_token' => $token, 'resend_after' => 60, 'expires_in' => 900];
    }

    public function resend(string $token): array
    {
        $id = DB::transaction(function () use ($token): string {
            $row = DB::table('pending_registrations')->where('pending_token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $row || $row->used_at || ! $row->payload || now()->subDay()->greaterThan($row->created_at)) {
                throw ValidationException::withMessages(['pending_token' => 'Registration expired. Return to the form and request a new code.']);
            }
            $this->requireConsent(json_decode(Crypt::decryptString($row->payload), true));
            if (now()->lessThan($row->resend_at) || ! Cache::add('registration-verification:'.$row->email_hash, true, 60)) {
                abort(429, 'Please wait before resending the code.');
            }
            $id = (string) Str::uuid();
            DB::table('pending_registrations')->where('email_hash', $row->email_hash)->update([
                'request_id' => $id, 'code_hash' => null, 'attempt_count' => 0, 'delivery_claimed_at' => null,
                'expires_at' => now()->addMinutes(15), 'resend_at' => now()->addSeconds(60), 'updated_at' => now(),
            ]);

            return $id;
        }, 3);
        $this->queue($id);

        return ['resend_after' => 60, 'expires_in' => 900];
    }

    private function queue(string $requestId): void
    {
        try {
            SendRegistrationVerificationCode::dispatch($requestId)->onConnection('database')
                ->onQueue('registration-verifications')->afterCommit();
        } catch (Throwable) {
            $this->invalidateDelivery($requestId);
            Log::error('Registration verification could not be queued. Check database and queue configuration.');
        }
    }

    /** Generate codes in the worker, so no queued payload contains a plaintext code or password. */
    public function deliver(string $requestId): void
    {
        try {
            $delivery = DB::transaction(function () use ($requestId): ?array {
                $row = DB::table('pending_registrations')->where('request_id', $requestId)->lockForUpdate()->first();
                if (! $row || $row->used_at || $row->delivery_claimed_at || now()->greaterThanOrEqualTo($row->expires_at)) {
                    return null;
                }
                $data = json_decode(Crypt::decryptString($row->payload), true);
                $this->requireConsent($data);
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                DB::table('pending_registrations')->where('request_id', $requestId)->update([
                    'code_hash' => Hash::make($this->secret($code)), 'delivery_claimed_at' => now(),
                    'expires_at' => now()->addMinutes(15), 'updated_at' => now(),
                ]);

                return [$data['email'], $code];
            }, 3);
            if (! $delivery) {
                return;
            }
            // Reuse the existing TLS-only Gmail transport, without mixing reset records or jobs.
            $smtp = config('mail.mailers.password_reset', []);
            if (config('mail.default') !== 'smtp' || ($smtp['transport'] ?? '') !== 'smtp'
                || empty($smtp['host']) || empty($smtp['username']) || empty($smtp['password']) || empty(config('mail.from.address'))) {
                Log::error('Registration verification SMTP is not configured. Check mail settings.');
                $this->invalidateDelivery($requestId);

                return;
            }
            Mail::mailer('password_reset')->to($delivery[0])->send(new RegistrationVerificationMail($delivery[1]));
        } catch (ValidationException) {
            Log::notice('Registration email was not sent because the pending request needs current consent.');
            $this->invalidateDelivery($requestId);
        } catch (Throwable) {
            Log::error('Registration verification email delivery failed. Check SMTP connectivity and configuration.');
            $this->invalidateDelivery($requestId);
        }
    }

    public function invalidateDelivery(string $requestId): void
    {
        try {
            DB::table('pending_registrations')->where('request_id', $requestId)->update([
                'code_hash' => null, 'delivery_claimed_at' => now(), 'updated_at' => now(),
            ]);
        } catch (Throwable) {
            Log::error('Registration verification cleanup failed. Check database availability.');
        }
    }

    private function secret(#[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /** The pending row lock and existing unique indexes protect final account creation. */
    public function verify(string $token, #[\SensitiveParameter] string $code): array
    {
        try {
            $result = DB::transaction(function () use ($token, $code): ?array {
                $row = DB::table('pending_registrations')->where('pending_token_hash', hash('sha256', $token))->lockForUpdate()->first();
                if (! $row || $row->used_at || ! $row->code_hash || $row->attempt_count >= 5 || now()->greaterThanOrEqualTo($row->expires_at)) {
                    return null;
                }
                if (! Hash::check($this->secret($code), $row->code_hash)) {
                    DB::table('pending_registrations')->where('email_hash', $row->email_hash)->update([
                        'attempt_count' => $row->attempt_count + 1, 'updated_at' => now(),
                    ]);

                    return null;
                }
                $data = json_decode(Crypt::decryptString($row->payload), true);
                $this->requireConsent($data);
                Validator::make($data, [
                    'email' => [Rule::unique('users', 'email')],
                    'mobile_number' => [Rule::unique('donor_profiles', 'mobile_number')],
                    'birth_date' => ['required', 'date_format:Y-m-d', new AdultDonor],
                    'middle_name' => ['nullable', 'string', 'regex:/^\p{L}$/uD'],
                ])->validate();
                // Recheck legacy number variants inside the final account-creation transaction.
                if (DonorProfile::whereIn('mobile_number', DonorIdentity::variants($data['mobile_number']))->exists()) {
                    throw ValidationException::withMessages(['mobile_number' => 'The mobile number has already been taken.']);
                }
                $user = User::create([
                    'name' => DonorProfile::accountName($data), 'email' => $data['email'],
                    'password' => $row->password_hash, 'email_verified_at' => now(),
                ]);
                // email_verified_at is server-owned and is not mass assignable.
                $user->forceFill(['email_verified_at' => now()])->save();
                $profile = $user->donorProfile()->create(Arr::except($data, array_merge(['email', 'terms_version', 'privacy_version'], self::CONSENT_FIELDS)));
                DB::table('user_consents')->insert([
                    'user_id' => $user->id, 'accepted_terms' => true, 'acknowledged_privacy' => true,
                    'acknowledged_prescreening' => true, 'terms_version' => $data['terms_version'],
                    'privacy_version' => $data['privacy_version'], 'accepted_at' => now(),
                ]);
                $session = $user->createToken('mobile')->plainTextToken;
                DB::table('pending_registrations')->where('email_hash', $row->email_hash)->update([
                    'used_at' => now(), 'verified_at' => now(), 'payload' => null, 'password_hash' => null,
                    'code_hash' => null, 'updated_at' => now(),
                ]);

                return ['message' => 'Registration successful', 'user' => $user, 'donor_profile' => $profile, 'token' => $session];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'The email or mobile number is already registered. Return to the form to update your details.']);
        }
        if ($result === null) {
            throw ValidationException::withMessages(['code' => 'The code is invalid, expired, or already used. Request a new code if needed.']);
        }

        return $result;
    }
}
