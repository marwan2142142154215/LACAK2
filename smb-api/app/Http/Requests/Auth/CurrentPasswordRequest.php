<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step-up re-authentication (§30): tindakan sensitif (disable 2FA, regenerate recovery
 * codes) wajib konfirmasi ulang password saat ini, bukan hanya mengandalkan token aktif.
 */
class CurrentPasswordRequest extends FormRequest
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
            // Guard 'sanctum' dispesifikasikan eksplisit — default guard web tidak relevan di sini.
            'current_password' => ['required', 'string', 'current_password:sanctum'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.current_password' => 'Password saat ini salah.',
        ];
    }
}
