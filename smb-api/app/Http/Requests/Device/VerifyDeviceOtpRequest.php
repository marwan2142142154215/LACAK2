<?php

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint PUBLIK (device user yang terkunci, tanpa akses admin, §24) — otorisasi
 * sesungguhnya adalah OTP itu sendiri (dicek di controller), bukan Sanctum.
 */
class VerifyDeviceOtpRequest extends FormRequest
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
            'code' => ['required', 'string', 'size:6'],
        ];
    }
}
