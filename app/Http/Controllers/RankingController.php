<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResultFilterRequest;
use App\Models\Player;
use App\Services\RankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class RankingController extends Controller
{
    public function index(ResultFilterRequest $request, RankingService $service): JsonResponse
    {
        $rows = $service->ranking($request->validated());
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 20);

        return response()->json(new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => $request->url(), 'query' => $request->query()]));
    }

    public function player(ResultFilterRequest $request, Player $player, RankingService $service): JsonResponse
    {
        return response()->json(['data' => $service->player($player, $request->validated())]);
    }
}
