<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResultFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'player_count' => ['sometimes', 'required', 'integer', 'between:2,15'],
            'status' => ['sometimes', 'required', Rule::in(['completed', 'interrupted'])],
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
