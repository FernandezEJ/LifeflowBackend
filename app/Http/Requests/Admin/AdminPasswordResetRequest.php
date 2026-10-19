<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\PasswordResetRequest;
use Illuminate\Validation\Rules\Password;

class AdminPasswordResetRequest extends PasswordResetRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        if (isset($rules['password'])) {
            $rules['password'][] = Password::min(10)->mixedCase()->numbers()->symbols();
        }

        return $rules;
    }
}
