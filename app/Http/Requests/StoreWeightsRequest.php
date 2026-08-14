<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWeightsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'zone_name' => ['required', 'array'],
            'zone_name.*' => ['required', 'string'],
            'weights' => ['nullable', 'array'],
            'weights.*' => ['nullable', 'array'],
            'weights.*.*' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
