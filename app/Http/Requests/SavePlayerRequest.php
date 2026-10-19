<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePlayerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $player = $this->route('player');
        $defaults = $player ? $player->only(['first_name', 'last_name', 'nickname', 'use_nickname', 'is_active']) : ['use_nickname' => false, 'is_active' => true];
        $this->merge(array_replace($defaults, $this->all()));
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:80'],
            'nickname' => [Rule::requiredIf($this->boolean('use_nickname')), 'nullable', 'string', 'max:40'],
            'use_nickname' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
