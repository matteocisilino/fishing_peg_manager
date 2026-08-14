<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePoolTournamentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            't_name' => ['required', 'string', 'max:255'],
            'pool_capacity' => ['required', 'integer', 'min:1'],
            'pool_zone_name' => ['required', 'array'],
            'pool_zone_name.*' => ['nullable', 'string', 'max:50'],
            'pool_zone_pegs' => ['required', 'array'],
            'pool_zone_pegs.*' => ['nullable', 'string'],
            'participant_name' => ['nullable', 'array'],
            'participant_name.*' => ['nullable', 'string', 'max:255'],
            'structure_lake_ids' => ['required', 'array'],
            'structure_lake_ids.*' => ['nullable', 'integer'],
        ];
    }
}
