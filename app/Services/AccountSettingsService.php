<?php

namespace App\Services;

use App\Jobs\SendEmailChangeCode;
use App\Mail\EmailChangeCodeMail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AccountSettingsService
{
    /** Lock the donor first; recheck the bearer session after any concurrent revocation. */
    private function owner(User $actor): User
    {
        $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $id = $actor->currentAccessToken()?->getKey();
        $session = $id ? $user->tokens()->whereKey($id)->first() : null;
        abort_unless($session && (! $session->expires_at || $session->expires_at->isFuture()), 401, 'Please log in again.');

        return $user;
    }

    private function requirePassword(User $user, #[\SensitiveParameter] string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }
    }

    private function requireEmail(User $user, string $email): void
    {
        if (strtolower($user->email) === $email || User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['new_email' => 'Choose a different, unused email address.']);
        }
    }

    private function secret(#[\SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /** CHANGE EMAIL: separate records, encrypted address, hashed token and worker-generated code. */
    public function request(User $actor, #[\SensitiveParameter] string $password, string $email): array
    {
        $token = bin2hex(random_bytes(32));
        $id = DB::transaction(function () use ($actor, $password, $email, $token): string {
            $user = $this->owner($actor);
            $this->requirePassword($user, $password);
            $this->requireEmail($user, $email);
            $old = DB::table('email_changes')->where('user_id', $user->id)->lockForUpdate()->first();
            if ($old && now()->lessThan($old->resend_at)) {
                abort(429, 'Please wait 60 seconds before requesting another code.');
            }
            $id = (string) Str::uuid();
            DB::table('email_changes')->updateOrInsert(['user_id' => $user->id], [
                'personal_access_token_id' => $actor->currentAccessToken()->getKey(),
                'pending_token_hash' => hash('sha256', $token), 'request_id' => $id,
                'new_email' => Crypt::encryptString($email), 'original_email_hash' => hash('sha256', $user->email),
                'credential_hash' => hash('sha256', $user->password), 'code_hash' => null,
                'expires_at' => now()->addMinutes(15), 'resend_at' => now()->addSeconds(60),
                'attempt_count' => 0, 'used_at' => null, 'delivery_claimed_at' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        }, 3);
        $this->queue($id);

        return ['pending_token' => $token, 'resend_after' => 60, 'expires_in' => 900];
    }

    private function pending(User $actor, User $user, string $token): ?object
    {
        $row = DB::table('email_changes')->where('user_id', $user->id)
            ->where('pending_token_hash', hash('sha256', $token))->lockForUpdate()->first();
        if (! $row || $row->used_at || $row->personal_access_token_id !== $actor->currentAccessToken()->getKey()
            || ! hash_equals($row->credential_hash, hash('sha256', $user->password))
            || ! hash_equals($row->original_email_hash, hash('sha256', $user->email))
            || now()->greaterThanOrEqualTo(Carbon::parse($row->created_at)->addDay())) {
            return null;
        }

        return $row;
    }

    public function resend(User $actor, string $token): array
    {
        $id = DB::transaction(function () use ($actor, $token): string {
            $user = $this->owner($actor);
            $row = $this->pending($actor, $user, $token);
            if (! $row) {
                throw ValidationException::withMessages(['pending_token' => 'Start a new email change request.']);
            }
            if (now()->lessThan($row->resend_at)) {
                abort(429, 'Please wait before resending the code.');
            }
            $this->requireEmail($user, Crypt::decryptString($row->new_email));
            $id = (string) Str::uuid();
            DB::table('email_changes')->where('user_id', $user->id)->update([
                'request_id' => $id, 'code_hash' => null, 'attempt_count' => 0, 'delivery_claimed_at' => null,
                'expires_at' => now()->addMinutes(15), 'resend_at' => now()->addSeconds(60), 'updated_at' => now(),
            ]);

            return $id;
        }, 3);
        $this->queue($id);

        return ['resend_after' => 60, 'expires_in' => 900];
    }

    private function queue(string $id): void
    {
        try {
            SendEmailChangeCode::dispatch($id)->onConnection('database')->onQueue('email-changes')->afterCommit();
        } catch (Throwable) {
            $this->invalidateDelivery($id);
            Log::error('Email change could not be queued. Check queue configuration.');
            abort(503, 'Email could not be queued. Please try again later.');
        }
    }

    public function deliver(string $id): void
    {
        try {
            $delivery = DB::transaction(function () use ($id): ?array {
                $row = DB::table('email_changes')->where('request_id', $id)->lockForUpdate()->first();
                if (! $row || $row->used_at || $row->delivery_claimed_at || now()->greaterThanOrEqualTo($row->expires_at)) {
                    return null;
                }
                $user = User::find($row->user_id);
                if (! $user || ! hash_equals($row->credential_hash, hash('sha256', $user->password))
                    || ! hash_equals($row->original_email_hash, hash('sha256', $user->email))) {
                    return null;
                }
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                DB::table('email_changes')->where('request_id', $id)->update([
                    'code_hash' => Hash::make($this->secret($code)), 'delivery_claimed_at' => now(), 'updated_at' => now(),
                ]);

                return [Crypt::decryptString($row->new_email), $code];
            }, 3);
            if (! $delivery) {
                return;
            }
            $smtp = config('mail.mailers.password_reset', []);
            if (config('mail.default') !== 'smtp' || ($smtp['transport'] ?? '') !== 'smtp'
                || empty($smtp['host']) || empty($smtp['username']) || empty($smtp['password']) || empty(config('mail.from.address'))) {
                $this->invalidateDelivery($id);
                Log::error('Email change SMTP is not configured.');

                return;
            }
            Mail::mailer('password_reset')->to($delivery[0])->send(new EmailChangeCodeMail($delivery[1]));
        } catch (Throwable) {
            $this->invalidateDelivery($id);
            Log::error('Email change delivery failed. Check SMTP configuration and connectivity.');
        }
    }

    public function invalidateDelivery(string $id): void
    {
        try {
            DB::table('email_changes')->where('request_id', $id)->update(['code_hash' => null, 'delivery_claimed_at' => now(), 'updated_at' => now()]);
        } catch (Throwable) {
            Log::error('Email change cleanup failed. Check database availability.');
        }
    }

    /** Wrong-attempt writes commit; successful ownership proof changes only the account email. */
    public function verify(User $actor, string $token, #[\SensitiveParameter] string $code): User
    {
        try {
            $result = DB::transaction(function () use ($actor, $token, $code): ?User {
                $user = $this->owner($actor);
                $row = $this->pending($actor, $user, $token);
                if (! $row || ! $row->code_hash || $row->attempt_count >= 5 || now()->greaterThanOrEqualTo($row->expires_at)) {
                    return null;
                }
                if (! Hash::check($this->secret($code), $row->code_hash)) {
                    DB::table('email_changes')->where('user_id', $user->id)->update(['attempt_count' => $row->attempt_count + 1, 'updated_at' => now()]);

                    return null;
                }
                $email = Crypt::decryptString($row->new_email);
                $this->requireEmail($user, $email);
                $oldEmail = $user->email;
                $user->forceFill(['email' => $email, 'email_verified_at' => now()])->save();
                DB::table('email_changes')->where('user_id', $user->id)->update(['used_at' => now(), 'code_hash' => null, 'updated_at' => now()]);
                $this->invalidateReset($user, $oldEmail);

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['new_email' => 'This email is no longer available. Start a new request.']);
        }
        if (! $result) {
            throw ValidationException::withMessages(['code' => 'The code is invalid, expired, or already used. Request a new code if needed.']);
        }

        return $result;
    }

    private function invalidateReset(User $user, string $email): void
    {
        DB::table('password_reset_codes')->where(function ($query) use ($user, $email): void {
            $query->where('user_id', $user->id)->orWhere('email_hash', hash('sha256', strtolower($email)));
        })->update(['used_at' => now(), 'code_hash' => null, 'reset_token_hash' => null, 'updated_at' => now()]);
        DB::table('password_reset_tokens')->where('email', $email)->delete();
    }

    /** CHANGE PASSWORD: preserve this bearer login, revoke other tokens and browser sessions. */
    public function password(User $actor, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $password): void
    {
        DB::transaction(function () use ($actor, $current, $password): void {
            $user = $this->owner($actor);
            $this->requirePassword($user, $current);
            if (Hash::check($password, $user->password)) {
                throw ValidationException::withMessages(['password' => 'Choose a password different from your current password.']);
            }
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            $user->tokens()->where('id', '!=', $actor->currentAccessToken()->getKey())->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('email_changes')->where('user_id', $user->id)->update(['used_at' => now(), 'code_hash' => null, 'updated_at' => now()]);
            $this->invalidateReset($user, $user->email);
        }, 3);
    }
}
