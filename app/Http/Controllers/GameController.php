<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResultFilterRequest;
use App\Http\Requests\SaveGameRequest;
use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Services\GameWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GameController extends Controller
{
    public function index(ResultFilterRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $games = Game::with('participants.player')
            ->when(isset($filters['date']), fn ($query) => $query->where('played_on', $filters['date']))
            ->when(isset($filters['player_count']), fn ($query) => $query->where('player_count', $filters['player_count']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('played_on')->orderByDesc('daily_number')
            ->paginate($filters['per_page'] ?? 20)->withQueryString();

        return GameResource::collection($games);
    }

    public function show(Game $game): GameResource
    {
        return new GameResource($game->load('participants.player'));
    }

    public function store(SaveGameRequest $request, GameWriter $writer): JsonResponse
    {
        [$game, $created] = $writer->create($request->gameData(), $request->user()->id);

        return (new GameResource($game->load('participants.player')))->response()->setStatusCode($created ? 201 : 200);
    }

    public function update(SaveGameRequest $request, Game $game, GameWriter $writer): GameResource
    {
        return new GameResource($writer->update($game, $request->gameData())->load('participants.player'));
    }
}
