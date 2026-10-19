<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class GameSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function game(): string
    {
        $id = (string) Str::uuid();
        DB::table('games')->insert(['id' => $id, 'played_on' => '2026-10-19', 'daily_number' => 1, 'player_count' => 2, 'status' => 'interrupted', 'created_by' => User::factory()->create()->id]);

        return $id;
    }

    public function test_interrupted_game_can_keep_multiple_unknown_places(): void
    {
        $id = $this->game();
        for ($i = 1; $i <= 2; $i++) {
            $player = Player::create(['first_name' => 'Jan', 'last_name' => 'Nowak']);
            DB::table('game_participants')->insert(['game_id' => $id, 'player_id' => $player->id, 'starting_order' => $i, 'place' => null]);
        }
        $this->assertDatabaseCount('game_participants', 2);
    }

    public function test_duplicate_finishing_place_is_rejected(): void
    {
        $id = $this->game();
        $a = Player::create(['first_name' => 'Jan', 'last_name' => 'Nowak']);
        $b = Player::create(['first_name' => 'Anna', 'last_name' => 'Nowak']);
        DB::table('game_participants')->insert(['game_id' => $id, 'player_id' => $a->id, 'starting_order' => 1, 'place' => 1]);
        $this->expectException(QueryException::class);
        DB::table('game_participants')->insert(['game_id' => $id, 'player_id' => $b->id, 'starting_order' => 2, 'place' => 1]);
    }

    public function test_participant_cannot_reference_missing_player(): void
    {
        $id = $this->game();
        $this->expectException(QueryException::class);
        DB::table('game_participants')->insert(['game_id' => $id, 'player_id' => 9999, 'starting_order' => 1, 'place' => 1]);
    }
}
