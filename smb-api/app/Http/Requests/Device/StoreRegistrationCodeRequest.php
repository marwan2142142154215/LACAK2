<?php

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRegistrationCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('devices.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['required', 'uuid', Rule::exists('sites', 'id')->whereNull('deleted_at')],
            'team_id' => [
                'required', 'uuid',
                Rule::exists('teams', 'id')->where('site_id', $this->input('site_id'))->whereNull('deleted_at'),
            ],
            // §17: short-lived. Default 15 menit kalau tidak diisi (lihat controller), maksimal 60 menit.
            'expires_in_minutes' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'team_id.exists' => 'Team tidak ditemukan di site yang dipilih.',
        ];
    }
}
