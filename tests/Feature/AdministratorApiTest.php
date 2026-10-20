<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdministratorApiTest extends TestCase
{
    use RefreshDatabase;

    private function administratorToken(array $attributes = [], array $abilities = ['admin']): string
    {
        return User::factory()->create($attributes)->createToken('test', $abilities)->plainTextToken;
    }

    public function test_login_returns_a_usable_token_without_exposing_password(): void
    {
        $user = User::factory()->create(['username' => 'admin']);
        $response = $this->postJson('/api/v1/auth/login', ['username' => 'admin', 'password' => 'test-password-123', 'device_name' => 'Android']);
        $response->assertOk()->assertJsonStructure(['token', 'expires_at', 'token_type']);
        $this->assertStringNotContainsString('test-password-123', $response->getContent());
        $this->withToken($response->json('token'))->getJson('/api/v1/auth/me')->assertOk()->assertExactJson(['data' => ['id' => $user->id, 'username' => 'admin']]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseMissing('personal_access_tokens', ['token' => $response->json('token')]);
    }

    public function test_unknown_wrong_password_and_disabled_accounts_are_rejected(): void
    {
        User::factory()->create(['username' => 'disabled', 'is_active' => false]);
        User::factory()->create(['username' => 'enabled']);
        foreach (['unknown', 'enabled', 'disabled'] as $username) {
            $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => $username === 'disabled' ? 'test-password-123' : 'wrong', 'device_name' => 'Android'])->assertUnauthorized();
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['username' => 'unknown', 'password' => 'wrong', 'device_name' => 'Android'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['username' => 'unknown', 'password' => 'wrong', 'device_name' => 'Android'])->assertStatus(429);
    }

    public function test_unauthenticated_requests_cannot_read_or_change_players(): void
    {
        $this->getJson('/api/v1/players')->assertUnauthorized();
        $this->postJson('/api/v1/players', ['first_name' => 'Anna', 'last_name' => 'Nowak'])->assertUnauthorized();
        $this->patchJson('/api/v1/players/1', ['is_active' => false])->assertUnauthorized();
        $this->assertDatabaseCount('players', 0);
    }

    public function test_token_without_admin_ability_is_forbidden(): void
    {
        $this->withToken($this->administratorToken([], ['read']))->getJson('/api/v1/players')->assertForbidden();
    }

    public function test_disabled_administrator_cannot_use_existing_token(): void
    {
        $this->withToken($this->administratorToken(['is_active' => false]))->getJson('/api/v1/players')->assertForbidden();
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = User::factory()->create()->createToken('expired', ['admin'], now()->subMinute());
        $this->withToken($token->plainTextToken)->getJson('/api/v1/players')->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_device_token(): void
    {
        $user = User::factory()->create();
        $first = $user->createToken('phone', ['admin']);
        $second = $user->createToken('other', ['admin']);
        $this->withToken($first->plainTextToken)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $first->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $second->accessToken->id]);
        $this->app['auth']->forgetGuards();
        $this->withToken($first->plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_create_update_and_deactivate_player_preserves_identity(): void
    {
        $this->withToken($this->administratorToken());
        $created = $this->postJson('/api/v1/players', ['first_name' => '  Łukasz ', 'last_name' => ' Żak ', 'nickname' => 'Lucky', 'use_nickname' => true, 'id' => 999]);
        $created->assertCreated()->assertJsonPath('data.display_name', 'Lucky')->assertJsonPath('data.is_active', true);
        $id = $created->json('data.id');
        $this->assertNotEquals(999, $id);
        $this->patchJson('/api/v1/players/'.$id, ['use_nickname' => false, 'is_active' => false])->assertOk()->assertJsonPath('data.display_name', 'Łukasz Ż.')->assertJsonPath('data.id', $id);
        $this->getJson('/api/v1/players?active=1')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/players')->assertJsonCount(1, 'data');
        $this->patchJson('/api/v1/players/'.$id, ['is_active' => true])->assertOk();
        $this->getJson('/api/v1/players?active=1')->assertJsonCount(1, 'data');
    }

    public function test_player_validation_and_partial_update_keep_nickname_consistent(): void
    {
        $this->withToken($this->administratorToken());
        $this->postJson('/api/v1/players', ['first_name' => ' ', 'last_name' => 'Nowak'])->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->postJson('/api/v1/players', ['first_name' => 'Anna', 'last_name' => 'Nowak', 'use_nickname' => true])->assertUnprocessable()->assertJsonValidationErrors('nickname');
        $player = Player::create(['first_name' => 'Anna', 'last_name' => 'Nowak', 'nickname' => 'Anka', 'use_nickname' => true]);
        $this->patchJson('/api/v1/players/'.$player->id, ['nickname' => null])->assertUnprocessable()->assertJsonValidationErrors('nickname');
        $this->patchJson('/api/v1/players/'.$player->id, ['nickname' => null, 'use_nickname' => false])->assertOk();
        $this->postJson('/api/v1/players', ['first_name' => str_repeat('a', 61), 'last_name' => 'Nowak', 'is_active' => 'yes'])->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'is_active']);
        $this->patchJson('/api/v1/players/9999', ['first_name' => 'Anna'])->assertNotFound();
    }

    public function test_pagination_has_stable_order_and_validated_limits(): void
    {
        $this->withToken($this->administratorToken());
        for ($i = 1; $i <= 3; $i++) {
            Player::create(['first_name' => 'Gracz'.$i, 'last_name' => 'Test']);
        }
        $this->getJson('/api/v1/players?per_page=2')->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/players?per_page=2&page=2')->assertJsonPath('data.0.first_name', 'Gracz3');
        $this->getJson('/api/v1/players?per_page=1000')->assertUnprocessable();
        $this->getJson('/api/v1/players?active=anything')->assertUnprocessable();
    }

    public function test_no_public_registration_or_player_delete_exists(): void
    {
        $this->postJson('/api/v1/register')->assertNotFound();
        $this->withToken($this->administratorToken())->deleteJson('/api/v1/players/1')->assertStatus(405);
    }

    public function test_create_administrator_command_hashes_password_and_rejects_duplicates(): void
    {
        $this->artisan('unorank:create-admin', ['username' => 'owner'])
            ->expectsQuestion('Hasło administratora', 'StrongPassword123')
            ->expectsQuestion('Powtórz hasło', 'StrongPassword123')->assertSuccessful();
        $this->assertNotEquals('StrongPassword123', User::where('username', 'owner')->first()->password);
        $this->artisan('unorank:create-admin', ['username' => 'owner'])
            ->expectsQuestion('Hasło administratora', 'StrongPassword123')
            ->expectsQuestion('Powtórz hasło', 'StrongPassword123')->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }
}
