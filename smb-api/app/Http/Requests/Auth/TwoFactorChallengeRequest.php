<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class TwoFactorChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'login_token' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'string'],
            'recovery_code' => ['required_without:code', 'string'],
            'device_name' => ['required', 'string', 'max:150'],
        ];
    }
}
