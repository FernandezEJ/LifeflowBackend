<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class SessionTokenPolicy
{
    public const STAFF_SECONDS = 12 * 60 * 60;

    public const DONOR_SECONDS = 30 * 24 * 60 * 60;

    public static function expiresFor(User $user): CarbonInterface
    {
        return now()->addSeconds($user->isAdminPanelUser() ? self::STAFF_SECONDS : self::DONOR_SECONDS);
    }

    /** Parse stored database timestamps strictly; empty/invalid values never mean "now". */
    public static function timestamp(mixed $value): ?CarbonImmutable
    {
        try {
            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value);
            }
            if (! is_string($value) || ! preg_match('/\A(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?\z/', $value, $match)) {
                return null;
            }
            $normalized = $match[1].'.'.str_pad($match[2] ?? '', 6, '0');
            $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s.u', $normalized, config('app.timezone', 'UTC'));

            return $date && $date->format('Y-m-d H:i:s.u') === $normalized ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function allows(PersonalAccessToken $token, ?User $owner = null): bool
    {
        try {
            $owner ??= $token->tokenable;
            if (! $owner instanceof User || (! $owner->isDonor() && ! $owner->isAdminPanelUser())) {
                return false;
            }
            $created = self::timestamp($token->getRawOriginal('created_at'));
            $now = now();
            if (! $created || $created->gt($now)) {
                return false;
            }
            $seconds = $owner->isAdminPanelUser() || $token->name === 'admin-web' ? self::STAFF_SECONDS : self::DONOR_SECONDS;
            if ($now->greaterThanOrEqualTo($created->addSeconds($seconds))) {
                return false;
            }
            $rawExpiry = $token->getRawOriginal('expires_at');
            $expiry = self::timestamp($rawExpiry);

            return $rawExpiry === null || ($expiry !== null && $now->lt($expiry));
        } catch (Throwable) {
            return false;
        }
    }

    /** A future role conversion may shorten a legacy lease, but can never renew it. */
    public static function restrictRoleChange(User $user): void
    {
        foreach ($user->tokens()->get() as $token) {
            $created = self::timestamp($token->getRawOriginal('created_at'));
            if (! $created) {
                continue;
            }
            $cutoff = $created->addSeconds(self::STAFF_SECONDS);
            $rawExpiry = $token->getRawOriginal('expires_at');
            $expiry = self::timestamp($rawExpiry);
            if ($rawExpiry === null || ($expiry !== null && $expiry->gt($cutoff))) {
                $token->forceFill(['expires_at' => $cutoff])->save();
            }
        }
    }
}
