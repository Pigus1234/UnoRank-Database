<?php

namespace App\Services;

use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RankingService
{
    public function ranking(array $filters): Collection
    {
        $aggregates = $this->aggregates($filters)->groupBy('player_id');
        $rows = Player::orderBy('id')->get()->map(fn (Player $player) => $this->statistics($player, $aggregates->get($player->id, collect())));
        if (isset($filters['date']) || isset($filters['player_count'])) {
            $rows = $rows->filter(fn ($row) => $row['games_played'] > 0);
        }
        $rows = $rows->sort(fn ($a, $b) => ($a['score'] ?? PHP_INT_MAX) <=> ($b['score'] ?? PHP_INT_MAX) ?: $b['games_played'] <=> $a['games_played'] ?: $a['player_id'] <=> $b['player_id'])->values();
        $previous = null;
        $rank = null;

        return $rows->map(function ($row, $index) use (&$previous, &$rank, $filters) {
            if ($row['score'] === null) {
                $rank = null;
            } elseif ($previous === null || $row['score'] !== $previous['score'] || $row['games_played'] !== $previous['games_played']) {
                $rank = $index + 1;
            }
            $previous = $row;

            return ['rank' => $rank, 'is_day_leader' => isset($filters['date']) && $rank === 1] + $row;
        });
    }

    public function player(Player $player, array $filters): array
    {
        return $this->statistics($player, $this->aggregates($filters, $player->id));
    }

    private function aggregates(array $filters, ?int $playerId = null): Collection
    {
        return DB::table('game_participants as participants')->join('games', 'games.id', '=', 'participants.game_id')
            ->where('games.status', 'completed')
            ->when($playerId !== null, fn ($query) => $query->where('participants.player_id', $playerId))
            ->when(isset($filters['date']), fn ($query) => $query->where('games.played_on', $filters['date']))
            ->when(isset($filters['player_count']), fn ($query) => $query->where('games.player_count', $filters['player_count']))
            ->select('participants.player_id', 'games.player_count', 'participants.place')->selectRaw('COUNT(*) as games_played')
            ->groupBy('participants.player_id', 'games.player_count', 'participants.place')->get();
    }

    private function statistics(Player $player, Collection $aggregates): array
    {
        $categories = $aggregates->groupBy('player_count')->map(function ($rows, $count) {
            $games = (int) $rows->sum('games_played');
            $sum = (int) $rows->sum(fn ($row) => $row->place * $row->games_played);

            return ['player_count' => (int) $count, 'games_played' => $games, 'sum_places' => $sum, 'score' => $this->roundedHundredths($sum * 100, $games) / 100];
        })->sortBy('player_count')->values();
        $histogram = $aggregates->groupBy('place')->map(fn ($rows, $place) => ['place' => (int) $place, 'count' => (int) $rows->sum('games_played')])->sortBy('place')->values();
        $categoryHundredths = (int) $categories->sum(fn ($row) => (int) round($row['score'] * 100));

        return [
            'player_id' => $player->id,
            'display_name' => $player->displayName(),
            'is_active' => $player->is_active,
            'score' => $categories->isEmpty() ? null : $this->roundedHundredths($categoryHundredths, $categories->count()) / 100,
            'games_played' => (int) $aggregates->sum('games_played'),
            'first_places' => (int) $aggregates->where('place', 1)->sum('games_played'),
            'second_places' => (int) $aggregates->where('place', 2)->sum('games_played'),
            'places' => $histogram->all(),
            'categories' => $categories->all(),
        ];
    }

    private function roundedHundredths(int $numerator, int $denominator): int
    {
        return intdiv($numerator, $denominator) + (($numerator % $denominator) * 2 >= $denominator ? 1 : 0);
    }
}
