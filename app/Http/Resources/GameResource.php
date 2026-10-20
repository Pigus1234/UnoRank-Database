<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'played_on' => $this->played_on->format('Y-m-d'),
            'daily_number' => $this->daily_number,
            'player_count' => $this->player_count,
            'status' => $this->status,
            'revision' => $this->revision,
            'updated_at' => $this->updated_at?->toISOString(),
            'participants' => $this->participants->sortBy(fn ($participant) => $participant->place ?? 100 + $participant->starting_order)->values()->map(fn ($participant) => [
                'player_id' => $participant->player_id,
                'display_name' => $participant->player->displayName(),
                'starting_order' => $participant->starting_order,
                'place' => $participant->place,
            ]),
        ];
    }
}
