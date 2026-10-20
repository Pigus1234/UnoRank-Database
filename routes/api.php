<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\RankingController;
use App\Http\Middleware\EnsureAdministrator;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::prefix('public')->middleware('throttle:public')->group(function () {
        Route::get('games', [GameController::class, 'index']);
        Route::get('games/{game}', [GameController::class, 'show']);
        Route::get('rankings', [RankingController::class, 'index']);
        Route::get('players/{player}/statistics', [RankingController::class, 'player']);
    });
    Route::middleware(['auth:sanctum', EnsureAdministrator::class, 'throttle:api'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('games', [GameController::class, 'index']);
        Route::get('games/{game}', [GameController::class, 'show']);
        Route::post('games', [GameController::class, 'store']);
        Route::put('games/{game}', [GameController::class, 'update']);
        Route::apiResource('players', PlayerController::class)->only(['index', 'store', 'update']);
    });
});
