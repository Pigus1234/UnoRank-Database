<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RankingApiTest extends TestCase
{
    use RefreshDatabase;

    private int $number = 0;

    private function player(string $name = 'Jan'): Player
    {
        return Player::create(['first_name' => $name, 'last_name' => 'PrywatneNazwisko']);
    }

    private function game(array $playersInFinishOrder, string $date = '2026-10-18', string $status = 'completed'): Game
    {
        $game = Game::create(['id' => (string) Str::uuid(), 'played_on' => $date, 'daily_number' => ++$this->number, 'player_count' => count($playersInFinishOrder), 'status' => $status, 'created_by' => User::firstOrCreate(['username' => 'owner'], ['password' => 'test-only'])->id]);
        foreach ($playersInFinishOrder as $index => $player) {
            $game->participants()->create(['player_id' => $player->id, 'starting_order' => $index + 1, 'place' => $status === 'interrupted' && $index > 0 ? null : $index + 1]);
        }

        return $game;
    }

    public function test_specification_example_uses_mean_of_rounded_categories(): void
    {
        [$a, $b, $c, $d, $e, $f, $g, $h] = collect(range(1, 8))->map(fn ($index) => $this->player('Gracz'.$index))->all();
        $this->game([$a, $b]);
        $this->game([$b, $a]);
        $this->game([$b, $a, $c, $d]);
        $this->game([$a, $b, $c, $d]);
        $this->game([$b, $a, $c, $d]);
        $this->game([$b, $c, $d, $e, $f, $g, $h, $a]);
        $response = $this->getJson('/api/v1/public/players/'.$a->id.'/statistics');
        $response->assertOk()->assertJsonPath('data.score', 3.72)->assertJsonPath('data.games_played', 6)
            ->assertJsonPath('data.first_places', 2)->assertJsonPath('data.second_places', 3)
            ->assertJsonPath('data.categories.0.score', 1.5)->assertJsonPath('data.categories.1.score', 1.67)
            ->assertJsonPath('data.categories.2.score', 8)->assertJsonPath('data.places.2.place', 8);
        $this->assertStringNotContainsString('PrywatneNazwisko', $response->getContent());
        $this->getJson('/api/v1/public/players/'.$a->id.'/statistics?player_count=4')->assertJsonPath('data.score', 1.67)->assertJsonPath('data.games_played', 3);
        $ranking = $this->getJson('/api/v1/public/rankings')->assertOk()->json('data');
        $this->assertEquals(3.72, collect($ranking)->firstWhere('player_id', $a->id)['score']);
    }

    public function test_day_category_and_interrupted_results_are_filtered_together(): void
    {
        $a = $this->player('Anna');
        $b = $this->player('Bartek');
        $c = $this->player('Celina');
        $this->game([$a, $b], '2026-10-17');
        $this->game([$b, $a], '2026-10-18');
        $this->game([$c, $a, $b], '2026-10-18');
        $this->game([$a, $b], '2026-10-18', 'interrupted');
        $this->getJson('/api/v1/public/rankings?date=2026-10-18&player_count=2')->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.player_id', $b->id)->assertJsonPath('data.0.is_day_leader', true)
            ->assertJsonPath('data.0.games_played', 1)->assertJsonPath('data.1.score', 2);
        $this->getJson('/api/v1/public/players/'.$a->id.'/statistics?date=2026-10-18')->assertJsonPath('data.games_played', 2)->assertJsonPath('data.first_places', 0);
        $this->getJson('/api/v1/public/rankings?date=2026-10-16')->assertJsonCount(0, 'data');
    }

    public function test_more_games_win_score_tie_and_complete_ties_share_rank(): void
    {
        $a = $this->player('Anna');
        $b = $this->player('Bartek');
        $c = $this->player('Celina');
        $d = $this->player('Daniel');
        $this->game([$a, $b]);
        $this->game([$b, $a]);
        $this->game([$a, $b]);
        $this->game([$b, $a]);
        $this->game([$c, $d]);
        $this->game([$d, $c]);
        $this->getJson('/api/v1/public/rankings?date=2026-10-18&per_page=2')->assertJsonPath('total', 4)
            ->assertJsonPath('data.0.rank', 1)->assertJsonPath('data.1.rank', 1)
            ->assertJsonPath('data.0.is_day_leader', true)->assertJsonPath('data.1.is_day_leader', true)->assertJsonPath('data.0.games_played', 4);
        $this->getJson('/api/v1/public/rankings?date=2026-10-18&per_page=2&page=2')->assertJsonPath('data.0.rank', 3)->assertJsonPath('data.1.rank', 3)->assertJsonPath('data.0.is_day_leader', false);
    }

    public function test_no_results_and_inactive_players_keep_history_without_invented_scores(): void
    {
        $a = $this->player();
        $b = $this->player();
        $empty = $this->player();
        $a->update(['is_active' => false, 'nickname' => 'As', 'use_nickname' => true]);
        $this->game([$a, $b]);
        $this->game([$empty, $a], status: 'interrupted');
        $this->getJson('/api/v1/public/rankings')->assertJsonPath('data.0.display_name', 'As')->assertJsonPath('data.0.is_active', false)
            ->assertJsonPath('data.2.rank', null)->assertJsonPath('data.2.score', null)->assertJsonPath('data.2.games_played', 0);
        $this->getJson('/api/v1/public/players/'.$empty->id.'/statistics')->assertJsonPath('data.score', null)->assertJsonCount(0, 'data.places');
        $this->getJson('/api/v1/public/players/99999/statistics')->assertNotFound();
    }

    public function test_editing_game_date_status_and_participants_recalculates_statistics(): void
    {
        $a = $this->player();
        $b = $this->player();
        $c = $this->player();
        $game = $this->game([$a, $b]);
        $this->withToken(User::first()->createToken('phone', ['admin'])->plainTextToken);
        $edit = ['revision' => 1, 'played_on' => '2026-10-19', 'daily_number' => 1, 'player_count' => 2, 'status' => 'completed', 'participants' => [
            ['player_id' => $c->id, 'place' => 1, 'starting_order' => 2], ['player_id' => $b->id, 'place' => 2, 'starting_order' => 1],
        ]];
        $this->putJson('/api/v1/games/'.$game->id, $edit)->assertOk();
        $this->getJson('/api/v1/public/rankings?date=2026-10-18')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/rankings?date=2026-10-19')->assertJsonPath('data.0.player_id', $c->id);
        $this->getJson('/api/v1/public/players/'.$a->id.'/statistics')->assertJsonPath('data.games_played', 0);
        $edit['revision'] = 2;
        $edit['status'] = 'interrupted';
        $this->putJson('/api/v1/games/'.$game->id, $edit)->assertOk();
        $this->getJson('/api/v1/public/rankings?date=2026-10-19')->assertJsonCount(0, 'data');
        $edit['revision'] = 3;
        $edit['status'] = 'completed';
        $this->putJson('/api/v1/games/'.$game->id, $edit)->assertOk();
        $this->getJson('/api/v1/public/players/'.$c->id.'/statistics')->assertJsonPath('data.games_played', 1);
    }
}
