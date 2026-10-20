<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Tests\TestCase;

class GameApiTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(): User
    {
        $this->app['auth']->forgetGuards();
        $user = User::factory()->create();
        $this->withToken($user->createToken('phone', ['admin'])->plainTextToken);

        return $user;
    }

    private function payload(int $count = 2): array
    {
        $players = collect(range(1, $count))->map(fn ($index) => Player::create(['first_name' => 'Gracz'.$index, 'last_name' => 'PrywatneNazwisko']));

        return ['id' => (string) Str::uuid(), 'played_on' => '2026-10-18', 'daily_number' => 1, 'player_count' => $count, 'status' => 'completed', 'participants' => $players->map(fn ($player, $index) => ['player_id' => $player->id, 'starting_order' => $index + 1, 'place' => $index + 1])->all()];
    }

    public function test_game_writes_and_private_history_require_an_active_administrator(): void
    {
        $data = $this->payload();
        $this->postJson('/api/v1/games', $data)->assertUnauthorized();
        $this->putJson('/api/v1/games/'.$data['id'], $data)->assertUnauthorized();
        $this->getJson('/api/v1/games')->assertUnauthorized();
        $user = User::factory()->create();
        $this->withToken($user->createToken('read', ['read'])->plainTextToken)->postJson('/api/v1/games', $data)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $user->update(['is_active' => false]);
        $this->withToken($user->createToken('inactive', ['admin'])->plainTextToken)->postJson('/api/v1/games', $data)->assertForbidden();
        $this->assertDatabaseCount('games', 0);
    }

    public function test_same_uuid_is_idempotent_after_normalization(): void
    {
        $user = $this->signIn();
        $data = $this->payload();
        $this->postJson('/api/v1/games', $data + ['revision' => null])->assertUnprocessable();
        $this->postJson('/api/v1/games', $data + ['created_by' => 999, 'submission_hash' => 'forged'])->assertCreated()->assertJsonPath('data.revision', 1);
        $data['id'] = strtoupper($data['id']);
        $data['daily_number'] = '1';
        $data['player_count'] = '2';
        $data['participants'] = array_reverse(array_map(fn ($participant) => array_map('strval', $participant), $data['participants']));
        $this->postJson('/api/v1/games', $data)->assertOk();
        $this->assertDatabaseCount('games', 1);
        $this->assertDatabaseCount('game_participants', 2);
        $this->assertDatabaseHas('games', ['created_by' => $user->id]);
    }

    public function test_changed_payload_or_different_author_cannot_reuse_uuid(): void
    {
        $this->signIn();
        $data = $this->payload();
        $this->postJson('/api/v1/games', $data)->assertCreated();
        $this->postJson('/api/v1/games', array_replace($data, ['daily_number' => 2]))->assertConflict()->assertJsonPath('code', 'game_id_conflict');
        $this->signIn();
        $this->postJson('/api/v1/games', $data)->assertConflict();
        $this->assertDatabaseCount('games', 1);
    }

    public function test_daily_number_collision_does_not_save_partial_data(): void
    {
        $this->signIn();
        $data = $this->payload();
        $this->postJson('/api/v1/games', $data)->assertCreated();
        $data['id'] = (string) Str::uuid();
        $this->postJson('/api/v1/games', $data)->assertConflict()->assertJsonPath('code', 'daily_number_conflict');
        $this->assertDatabaseCount('games', 1);
        $this->assertDatabaseCount('game_participants', 2);
        $data['daily_number'] = 2;
        $this->postJson('/api/v1/games', $data)->assertCreated();
    }

    public function test_interrupted_game_allows_unknown_places_and_inactive_historical_players(): void
    {
        $this->signIn();
        $data = $this->payload(3);
        Player::whereKey($data['participants'][0]['player_id'])->update(['is_active' => false]);
        $data['status'] = 'interrupted';
        $data['participants'][1]['place'] = null;
        $data['participants'][2]['place'] = null;
        $this->postJson('/api/v1/games', $data)->assertCreated()->assertJsonPath('data.participants.2.place', null);
        $this->assertDatabaseCount('game_participants', 3);
    }

    public function test_invalid_results_are_rejected_without_writing_rows(): void
    {
        $this->signIn();
        $data = $this->payload();
        $mutations = [
            ['player_count' => 1], ['player_count' => 16], ['player_count' => 3], ['played_on' => '9999-01-01'],
            ['played_on' => '2026-02-30'], ['played_on' => '0001-01-01'], ['daily_number' => 0], ['daily_number' => 4294967296],
            ['id' => 'invalid'], ['status' => 'draft'], ['participants' => 'wrong'],
        ];
        foreach ([['place', null], ['place', 2], ['place', 3], ['starting_order', 2], ['starting_order', 3], ['player_id', 9999], ['player_id', $data['participants'][1]['player_id']]] as [$key, $value]) {
            $mutations[] = ['participants' => [array_replace($data['participants'][0], [$key => $value]), $data['participants'][1]]];
        }
        foreach ($mutations as $mutation) {
            $this->postJson('/api/v1/games', array_replace($data, $mutation))->assertUnprocessable();
        }
        $this->assertDatabaseCount('games', 0);
        $this->assertDatabaseCount('game_participants', 0);
    }

    public function test_edit_protects_against_stale_updates_and_old_replays(): void
    {
        $this->signIn();
        $data = $this->payload();
        $this->postJson('/api/v1/games', $data)->assertCreated();
        $edit = Arr::except($data, 'id') + ['revision' => 1];
        $this->putJson('/api/v1/games/'.$data['id'], $edit + ['id' => null])->assertUnprocessable();
        $edit['participants'][0]['place'] = 2;
        $edit['participants'][1]['place'] = 1;
        $this->putJson('/api/v1/games/'.$data['id'], $edit)->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.participants.0.player_id', $data['participants'][1]['player_id']);
        $this->putJson('/api/v1/games/'.$data['id'], $edit)->assertConflict()->assertJsonPath('code', 'revision_conflict');
        $this->postJson('/api/v1/games', $data)->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.participants.0.player_id', $data['participants'][1]['player_id']);
        $this->assertDatabaseCount('game_participants', 2);
        $this->getJson('/api/v1/public/players/'.$data['participants'][0]['player_id'].'/statistics')->assertOk()->assertJsonPath('data.score', 2);
    }

    public function test_failed_edit_preserves_revision_and_participants(): void
    {
        $this->signIn();
        $first = $this->payload();
        $second = $this->payload();
        $second['daily_number'] = 2;
        $this->postJson('/api/v1/games', $first)->assertCreated();
        $this->postJson('/api/v1/games', $second)->assertCreated();
        $edit = Arr::except($second, 'id') + ['revision' => 1];
        $edit['daily_number'] = 1;
        $this->putJson('/api/v1/games/'.$second['id'], $edit)->assertConflict()->assertJsonPath('code', 'daily_number_conflict');
        $this->assertDatabaseHas('games', ['id' => $second['id'], 'revision' => 1, 'daily_number' => 2]);
        $this->assertDatabaseCount('game_participants', 4);
        unset($edit['revision']);
        $this->putJson('/api/v1/games/'.$second['id'], $edit)->assertUnprocessable();
    }

    public function test_public_history_is_paginated_filtered_and_does_not_expose_private_data(): void
    {
        $this->signIn();
        $first = $this->payload();
        $second = $this->payload(3);
        $second['played_on'] = '2026-10-19';
        $second['status'] = 'interrupted';
        $this->postJson('/api/v1/games', $first)->assertCreated();
        $this->postJson('/api/v1/games', $second)->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => '']);
        $response = $this->getJson('/api/v1/public/games?per_page=1');
        $response->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $second['id']);
        foreach (['PrywatneNazwisko', 'created_by', 'submission_hash', 'password', 'username'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->getJson('/api/v1/public/games?per_page=1&page=2')->assertJsonPath('data.0.id', $first['id']);
        $this->getJson('/api/v1/public/games?date=2026-10-18&player_count=2&status=completed')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/public/games/'.$first['id'])->assertOk()->assertJsonCount(2, 'data.participants');
        $this->getJson('/api/v1/public/games/'.Str::uuid())->assertNotFound();
        $this->postJson('/api/v1/public/games', $first)->assertStatus(405);
        $this->signIn();
        $this->deleteJson('/api/v1/games/'.$first['id'])->assertStatus(405);
    }

    public function test_maximum_table_size_and_invalid_public_filters(): void
    {
        $this->signIn();
        $this->postJson('/api/v1/games', $this->payload(15))->assertCreated()->assertJsonCount(15, 'data.participants');
        foreach (['per_page=101', 'page=0', 'date=2026-02-30', 'player_count=16', 'status=unknown'] as $filter) {
            $this->getJson('/api/v1/public/games?'.$filter)->assertUnprocessable();
        }
    }
}
