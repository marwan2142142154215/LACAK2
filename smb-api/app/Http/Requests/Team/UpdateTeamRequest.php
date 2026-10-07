<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamRequest extends FormRequest
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
        $siteId = $this->input('site_id', $this->route('team')?->site_id);

        return [
            'site_id' => ['sometimes', 'required', 'uuid', Rule::exists('sites', 'id')->whereNull('deleted_at')],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'code' => [
                'sometimes', 'required', 'string', 'max:30', 'alpha_dash',
                Rule::unique('teams', 'code')
                    ->where('site_id', $siteId)
                    ->ignore($this->route('team'))
                    ->whereNull('deleted_at'),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
