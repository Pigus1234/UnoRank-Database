<?php

namespace App\Services;

use App\Models\Game;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class GameWriter
{
    public function create(array $data, int $administratorId): array
    {
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($data, $administratorId, $hash) {
                if ($game = Game::find($data['id'])) {
                    return [$this->replay($game, $hash, $administratorId), false];
                }
                $game = Game::create(Arr::except($data, 'participants') + ['created_by' => $administratorId, 'submission_hash' => $hash, 'revision' => 1]);
                $game->participants()->createMany($data['participants']);

                return [$game, true];
            }, 3);
        } catch (QueryException $exception) {
            if ($game = Game::find($data['id'])) {
                return [$this->replay($game, $hash, $administratorId), false];
            }
            $this->checkNumberConflict($data);
            throw $exception;
        }
    }

    public function update(Game $game, array $data): Game
    {
        try {
            return DB::transaction(function () use ($game, $data) {
                $locked = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
                if ($locked->revision !== (int) $data['revision']) {
                    $this->conflict('revision_conflict', 'Wynik został już zmieniony. Pobierz aktualną wersję przed edycją.');
                }
                $locked->fill(Arr::except($data, ['participants', 'revision']));
                $locked->revision++;
                $locked->save();
                $locked->participants()->delete();
                $locked->participants()->createMany($data['participants']);

                return $locked;
            }, 3);
        } catch (QueryException $exception) {
            $this->checkNumberConflict($data, $game->id);
            throw $exception;
        }
    }

    private function replay(Game $game, string $hash, int $administratorId): Game
    {
        if ((int) $game->created_by !== $administratorId || $game->submission_hash !== $hash) {
            $this->conflict('game_id_conflict', 'Ten identyfikator rozgrywki jest już zapisany z innymi danymi.');
        }

        return $game;
    }

    private function checkNumberConflict(array $data, ?string $exceptId = null): void
    {
        if (Game::where('played_on', $data['played_on'])->where('daily_number', $data['daily_number'])->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))->exists()) {
            $this->conflict('daily_number_conflict', 'Numer rozgrywki w tym dniu jest już zajęty. Wybierz inny numer.');
        }
    }

    private function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message, 'code' => $code], 409));
    }
}
