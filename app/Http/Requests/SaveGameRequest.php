<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveGameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => [$this->isMethod('POST') ? 'required' : 'prohibited', 'uuid'],
            'revision' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1', 'max:4294967294'],
            'played_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:today'],
            'daily_number' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'player_count' => ['required', 'integer', 'between:2,15'],
            'status' => ['required', Rule::in(['completed', 'interrupted'])],
            'participants' => ['required', 'array', 'list', 'min:2', 'max:15'],
            'participants.*' => ['required', 'array:player_id,starting_order,place'],
            'participants.*.player_id' => ['required', 'integer', 'min:1', 'distinct', 'exists:players,id'],
            'participants.*.starting_order' => ['required', 'integer', 'between:1,15', 'distinct'],
            'participants.*.place' => ['present', 'nullable', 'integer', 'between:1,15', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $count = (int) $this->input('player_count');
            $participants = collect($this->input('participants'));
            if ($participants->count() !== $count) {
                $validator->errors()->add('participants', 'Liczba uczestników musi odpowiadać liczbie graczy.');
            }
            if ($participants->pluck('starting_order')->map(fn ($value) => (int) $value)->sort()->values()->all() !== range(1, $count)) {
                $validator->errors()->add('participants', 'Kolejność rozpoczynania musi obejmować wszystkich graczy bez powtórzeń.');
            }
            $places = $participants->pluck('place')->filter(fn ($place) => $place !== null)->map(fn ($place) => (int) $place);
            if ($places->contains(fn ($place) => $place > $count) || ($this->input('status') === 'completed' && $places->sort()->values()->all() !== range(1, $count))) {
                $validator->errors()->add('participants', 'Zakończona gra wymaga wszystkich miejsc. Miejsce nie może przekraczać liczby graczy.');
            }
        }];
    }

    public function gameData(): array
    {
        $data = $this->safe()->only(['played_on', 'daily_number', 'player_count', 'status', 'participants']);
        if ($this->isMethod('POST')) {
            $data['id'] = strtolower($this->validated('id'));
        } else {
            $data['revision'] = (int) $this->validated('revision');
        }
        $data['daily_number'] = (int) $data['daily_number'];
        $data['player_count'] = (int) $data['player_count'];
        $data['participants'] = collect($data['participants'])->map(fn ($participant) => [
            'player_id' => (int) $participant['player_id'],
            'starting_order' => (int) $participant['starting_order'],
            'place' => $participant['place'] === null ? null : (int) $participant['place'],
        ])->sortBy('player_id')->values()->all();

        return $data;
    }
}
