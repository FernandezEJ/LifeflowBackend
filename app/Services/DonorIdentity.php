<?php

namespace App\Services;

class DonorIdentity
{
    /** One canonical number for registration, profile edits, and passwordless lookup. */
    public static function mobile(string $value): string
    {
        $value = preg_replace('/[\s()-]/', '', $value);
        if (preg_match('/^09[0-9]{9}$/D', $value)) {
            $value = '+63'.substr($value, 1);
        } elseif (preg_match('/^639[0-9]{9}$/D', $value)) {
            $value = '+'.$value;
        }

        return $value;
    }

    /** Existing local-format data stays untouched, but still maps to the same account. */
    public static function variants(string $value): array
    {
        return [$value, substr($value, 1), '0'.substr($value, 3)];
    }
}
