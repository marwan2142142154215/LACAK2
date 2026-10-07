<?php

namespace App\Http\Requests\Device;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        // §40: permission per jenis command (lock/unlock lebih sensitif dari location/camera
        // di beberapa organisasi) — untuk sekarang semua command pakai 'devices.command' yang
        // sama (§40 sudah mendaftarkan ini sebagai permission generik); izin lebih granular
        // (per command_type) bisa ditambah kalau ada kebutuhan nyata (§66).
        return $this->user()->can('devices.command');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'command_type' => ['required', 'string', Rule::in(DeviceCommand::TYPES)],
            'payload' => ['sometimes', 'nullable', 'array'],
            // §22: idempotency_key OPSIONAL dari caller (retry aman) — kalau tidak diisi,
            // server generate otomatis (setiap request = command baru).
            'idempotency_key' => ['sometimes', 'string', 'max:100'],
            // §21: expiry wajib ada, default masuk akal per tipe command (lihat controller).
            'expires_in_seconds' => ['sometimes', 'integer', 'min:5', 'max:3600'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var Device|null $device */
            $device = $this->route('device');
            if ($device && ! $device->is_active) {
                $validator->errors()->add('device', 'Device tidak aktif, command tidak bisa dikirim.');
            }
        });
    }
}
