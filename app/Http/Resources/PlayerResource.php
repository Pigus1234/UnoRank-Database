<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'nickname' => $this->nickname,
            'use_nickname' => $this->use_nickname,
            'is_active' => $this->is_active,
            'display_name' => $this->resource->displayName(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
