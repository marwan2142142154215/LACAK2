<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('teams.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'site_id' => ['required', 'uuid', Rule::exists('sites', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:150'],
            'code' => [
                'required', 'string', 'max:30', 'alpha_dash',
                Rule::unique('teams', 'code')
                    ->where('site_id', $this->input('site_id'))
                    ->whereNull('deleted_at'),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
