<?php

namespace App\Models;

use App\Services\SessionTokenPolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /** Invalid stored dates reach the authentication policy as invalid, rather than throwing a 500. */
    protected function expiresAt(): Attribute
    {
        return Attribute::get(fn (mixed $value) => SessionTokenPolicy::timestamp($value));
    }
}
