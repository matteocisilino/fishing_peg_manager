<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeamTournamentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            't_name' => ['required', 'string', 'max:255'],
            'sector_size' => ['required', 'integer', Rule::in([5, 10])],
            'team_size' => ['required', 'integer', 'min:1'],
            'allow_edge_repeats' => ['required', 'boolean'],
            'structure_lake_ids' => ['required', 'array'],
            'structure_lake_ids.*' => ['nullable', 'integer'],
        ];
    }
}
