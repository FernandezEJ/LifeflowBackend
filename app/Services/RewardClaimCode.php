<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;

class RewardClaimCode
{
    public function generate(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = 'LFW-';
        for ($index = 0; $index < 6; $index++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /**
     * Domain-separated keyed lookup supports APP_KEY rotation without rewriting history.
     * Retain the original key in APP_PREVIOUS_KEYS while its claim records need lookup/decryption.
     *
     * @return list<string> Current-key digest first, followed by previous-key digests.
     */
    public function digests(#[\SensitiveParameter] string $code): array
    {
        return array_values(array_unique(array_map(
            fn (string $key): string => hash_hmac('sha256', "lifeflow:reward-claim:v1\0".$code, $key),
            Crypt::getAllKeys(),
        )));
    }
}
