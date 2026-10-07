<?php

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCameraRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('devices.camera');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'camera_facing' => ['required', 'string', Rule::in(['FRONT', 'BACK'])],
        ];
    }
}
