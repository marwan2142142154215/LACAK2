<?php

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Otorisasi nyata ada di middleware 'device.auth' (AuthenticateDeviceCredential) — bukan
 * di authorize() di sini, karena request ini tidak punya Sanctum user untuk dicek $this->user().
 */
class DeviceHeartbeatRequest extends FormRequest
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
            'device_id' => ['required', 'uuid'],
            'public_token_id' => ['required', 'uuid'],
            'device_secret' => ['required', 'string'],

            'battery_level' => ['nullable', 'integer', 'min:0', 'max:100'],
            'network_type' => ['nullable', 'string', 'in:WIFI,CELLULAR,ETHERNET,VPN,NONE,UNKNOWN'],
            'connection_state' => ['nullable', 'string', 'max:20'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'android_version' => ['nullable', 'string', 'max:20'],
        ];
    }
}
