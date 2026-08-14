<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTournamentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tournament = $this->route('tournament');

        $rules = [
            't_name' => ['required', 'string', 'max:255'],
            'allow_edge_repeats' => ['nullable', 'boolean'],
            'structure_lake_ids' => ['required', 'array'],
            'structure_lake_ids.*' => ['nullable', 'integer'],
        ];

        if ($tournament) {
            if (!$tournament->isPool()) {
                $rules['sector_size'] = ['required', 'integer', Rule::in([5, 10])];
            }
            if ($tournament->isTeam()) {
                $rules['team_size'] = ['required', 'integer', 'min:1'];
            }
            if ($tournament->isPool()) {
                $rules['pool_capacity'] = ['required', 'integer', 'min:1'];
                $rules['pool_zone_name'] = ['required', 'array'];
                $rules['pool_zone_name.*'] = ['nullable', 'string', 'max:50'];
                $rules['pool_zone_pegs'] = ['required', 'array'];
                $rules['pool_zone_pegs.*'] = ['nullable', 'string'];
            }
        }

        return $rules;
    }
}
