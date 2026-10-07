<?php

namespace App\Http\Requests\Site;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sites.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            // whereNull('deleted_at') WAJIB: index unik di DB adalah partial (lihat migration
            // sites), kalau tidak disamakan di sini, kode yg sudah soft-deleted akan salah
            // ditolak sebagai "sudah dipakai" padahal DB sebenarnya mengizinkannya dipakai ulang.
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('sites', 'code')->whereNull('deleted_at')],
            'address' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
