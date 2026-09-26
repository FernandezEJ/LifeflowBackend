<?php

namespace App\Services;

use App\Jobs\SendPasswordResetCode;
use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasswordResetService
{
    public const NOTICE = 'If an account is registered with this email, password reset instructions will be sent shortly.';

    public const CODE_MINUTES = 15;

    public const TOKEN_MINUTES = 10;

    public const RESEND_SECONDS = 60;

    private function emailHash(string $email): string
    {
        return hash('sha256', $email);
    }

    /**
     * Keep account lookup and SMTP off the public request path. Both known and
     * unknown addresses receive the same response and encrypted queued work.
     */
    public function requestCode(string $email): void
    {
        $key = $this->emailHash($email);
        $requestId = (string) Str::uuid();
        try {
            if (! Cache::add('password-reset-request:'.$key, true, self::RESEND_SECONDS)) {
                return;
            }

            DB::table('password_reset_codes')->upsert([[
                'email_hash' => $key, 'request_id' => $requestId, 'user_id' => null,
                'code_hash' => null, 'expires_at' => now()->addMinutes(self::CODE_MINUTES),
                'used_at' => null, 'attempt_count' => 0, 'verified_at' => null,
                'reset_token_hash' => null, 'reset_token_expires_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]], ['email_hash']);

            SendPasswordResetCode::dispatch($email, $requestId)
                ->onConnection('database')->onQueue('password-resets')->afterCommit();
        } catch (Throwable) {
            Log::error('Password reset request could not be queued. Check database, cache and queue configuration.');
            $this->invalidate($requestId);
        }
    }

    /**
     * Generate the plaintext code only inside the worker, never in queue data.
     * Claim once before sending; retries and old queued requests cannot resend it.
     */
    public function deliver(string $email, string $requestId): void
    {
        try {
            $code = DB::transaction(function () use ($email, $requestId): ?string {
                $user = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
                $row = DB::table('password_reset_codes')->where('email_hash', $this->emailHash($email))
                    ->lockForUpdate()->first();

                if (! $row || $row->request_id !== $requestId || $row->used_at
                    || $row->code_hash || now()->greaterThanOrEqualTo($row->expires_at)) {
                    return null;
                }
                if (! $user) {
                    DB::table('password_reset_codes')->where('request_id', $requestId)
                        ->update(['used_at' => now(), 'updated_at' => now()]);

                    return null;
                }

                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                DB::table('password_reset_codes')->where('request_id', $requestId)->update([
                    'user_id' => $user->id,
                    'code_hash' => Hash::make($this->codeSecret($code)),
                    'expires_at' => now()->addMinutes(self::CODE_MINUTES), 'updated_at' => now(),
                ]);

                return $code;
            }, 3);

            if ($code === null) {
                return;
            }
            $smtp = config('mail.mailers.password_reset', []);
            if (config('mail.default') !== 'smtp' || ($smtp['transport'] ?? null) !== 'smtp'
                || empty($smtp['host']) || empty($smtp['username']) || empty($smtp['password'])
                || empty(config('mail.from.address'))) {
                Log::error('Password reset email is not configured. Configure SMTP host, credentials and sender.');
                $this->invalidate($requestId);

                return;
            }
            Mail::mailer('password_reset')->to($email)->send(new PasswordResetCodeMail($code));
        } catch (Throwable) {
            // SMTP exceptions may include credentials or message contents; never log them.
            Log::error('Password reset email delivery failed. Check SMTP configuration and connectivity.');
            $this->invalidate($requestId);
        }
    }

    public function invalidate(string $requestId): void
    {
        try {
            DB::table('password_reset_codes')->where('request_id', $requestId)->update([
                'used_at' => now(), 'code_hash' => null, 'reset_token_hash' => null, 'updated_at' => now(),
            ]);
        } catch (Throwable) {
            Log::error('Password reset cleanup failed. Check database availability.');
        }
    }

    private function codeSecret(#[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /** A locked counter commits even when the submitted code is wrong. */
    public function verify(string $email, #[\SensitiveParameter] string $code): string
    {
        $token = DB::transaction(function () use ($email, $code): ?string {
            $row = DB::table('password_reset_codes')->where('email_hash', $this->emailHash($email))
                ->lockForUpdate()->first();
            if (! $row || $row->used_at || $row->verified_at || ! $row->code_hash
                || $row->attempt_count >= 5 || now()->greaterThanOrEqualTo($row->expires_at)) {
                return null;
            }
            if (! Hash::check($this->codeSecret($code), $row->code_hash)) {
                DB::table('password_reset_codes')->where('email_hash', $row->email_hash)->update([
                    'attempt_count' => $row->attempt_count + 1,
                    'used_at' => $row->attempt_count >= 4 ? now() : null, 'updated_at' => now(),
                ]);

                return null;
            }
            $token = bin2hex(random_bytes(32));
            DB::table('password_reset_codes')->where('email_hash', $row->email_hash)->update([
                'verified_at' => now(), 'reset_token_hash' => hash('sha256', $token),
                'reset_token_expires_at' => now()->addMinutes(self::TOKEN_MINUTES), 'updated_at' => now(),
            ]);

            return $token;
        }, 3);

        if ($token === null) {
            throw ValidationException::withMessages(['code' => 'The code is invalid or expired. Request a new code if needed.']);
        }

        return $token;
    }

    /** Donor-first locking makes consuming authorization and revoking sessions atomic. */
    public function reset(string $email, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): void
    {
        $changed = DB::transaction(function () use ($email, $token, $password): bool {
            $user = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
            $row = DB::table('password_reset_codes')->where('email_hash', $this->emailHash($email))
                ->lockForUpdate()->first();
            if (! $user || ! $row || $row->user_id !== $user->id || $row->used_at
                || ! $row->verified_at || ! $row->reset_token_hash || ! $row->reset_token_expires_at
                || now()->greaterThanOrEqualTo($row->reset_token_expires_at)
                || ! hash_equals($row->reset_token_hash, hash('sha256', $token))) {
                return false;
            }

            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            DB::table('password_reset_codes')->where('email_hash', $row->email_hash)->update([
                'used_at' => now(), 'code_hash' => null, 'reset_token_hash' => null, 'updated_at' => now(),
            ]);
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();

            return true;
        }, 3);

        if (! $changed) {
            throw ValidationException::withMessages(['reset_token' => 'Reset authorization is invalid or expired. Request a new code.']);
        }
    }
}
