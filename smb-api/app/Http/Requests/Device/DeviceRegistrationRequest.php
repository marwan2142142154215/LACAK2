<?php

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Endpoint ini PUBLIK (tidak ada user login) — device belum punya identitas sampai
 * registrasi berhasil. Gate otorisasi sesungguhnya adalah validitas `code` itu sendiri,
 * dicek di controller (bukan di sini), plus rate limiting per-IP (§57) di routes/api.php.
 */
class DeviceRegistrationRequest extends FormRequest
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
            'code' => ['required', 'string', 'size:20'],

            // §67: capability report jujur — dikirim device, bukan diasumsikan server.
            'android_api_level' => ['nullable', 'integer', 'min:26', 'max:36'],
            'android_version' => ['nullable', 'string', 'max:20'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'manufacturer' => ['nullable', 'string', 'max:50'],
            'model' => ['nullable', 'string', 'max:100'],
            'capability_report' => ['nullable', 'array'],
            'capability_report.camera_available' => ['sometimes', 'boolean'],
            'capability_report.front_camera_available' => ['sometimes', 'boolean'],
            'capability_report.back_camera_available' => ['sometimes', 'boolean'],
            'capability_report.location_available' => ['sometimes', 'boolean'],
            'capability_report.managed_device' => ['sometimes', 'boolean'],
            'capability_report.device_owner' => ['sometimes', 'boolean'],
            'capability_report.foreground_service_available' => ['sometimes', 'boolean'],
            'capability_report.notification_permission' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Registration code wajib diisi.',
            'code.size' => 'Format registration code tidak valid.',
        ];
    }
}
