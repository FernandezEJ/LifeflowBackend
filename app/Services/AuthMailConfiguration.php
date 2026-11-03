<?php

namespace App\Services;

class AuthMailConfiguration
{
    /** Keep the legacy SMTP guard; Brevo is an explicit named-mailer override only. */
    public static function configured(): bool
    {
        $mailer = config('mail.mailers.password_reset', []);
        if (! is_array($mailer) || empty(config('mail.from.address'))) {
            return false;
        }
        if (($mailer['transport'] ?? null) === 'brevo') {
            return self::brevoConfigured();
        }

        return config('mail.default') === 'smtp' && ($mailer['transport'] ?? null) === 'smtp'
            && ! empty($mailer['host']) && ! empty($mailer['username']) && ! empty($mailer['password']);
    }

    public static function brevoConfigured(): bool
    {
        $key = config('services.brevo.key');

        return is_string($key) && trim($key) !== '' && ! preg_match('/[\r\n]/', $key);
    }
}
