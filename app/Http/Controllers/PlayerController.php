<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePlayerRequest;
use App\Http\Resources\PlayerResource;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlayerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['active' => ['sometimes', 'boolean'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return PlayerResource::collection(Player::query()
            ->when(array_key_exists('active', $filters), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('id')->paginate($filters['per_page'] ?? 50)->withQueryString());
    }

    public function store(SavePlayerRequest $request): PlayerResource
    {
        return new PlayerResource(Player::create($request->validated()));
    }

    public function update(SavePlayerRequest $request, Player $player): PlayerResource
    {
        $player->update($request->validated());

        return new PlayerResource($player->refresh());
    }
}
