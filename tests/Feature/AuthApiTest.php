<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_correct_credentials()
    {
        $user = User::factory()->create([
            'email' => 'test@prepyourmeal.local',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => 'test@prepyourmeal.local',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'token',
                'user',
            ])
            ->assertJsonFragment([
                'status' => 'success',
            ]);
    }

    public function test_login_fails_with_invalid_credentials()
    {
        $user = User::factory()->create([
            'email' => 'test@prepyourmeal.local',
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => 'test@prepyourmeal.local',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'message' => 'Invalid credentials',
            ]);
    }

    public function test_authenticated_user_can_logout()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Successfully logged out.',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_authenticated_user_can_delete_their_account()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->deleteJson('/user');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Account permanently deleted.',
            ]);

        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
        ]);
    }

    public function test_user_can_register_with_valid_data()
    {
        $response = $this->postJson('/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'supersecret123',
            'password_confirmation' => 'supersecret123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'message',
                'token',
                'user' => ['id', 'name', 'email'],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'name' => 'John Doe',
        ]);
    }

    public function test_registration_fails_if_passwords_do_not_match()
    {
        $response = $this->postJson('/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'supersecret123',
            'password_confirmation' => 'differentpassword',
        ]);

        $response->assertStatus(422)
            // HIER FEHLTE DAS 'data' ALS ZWEITER PARAMETER
            ->assertJsonValidationErrors(['password'], 'data');
    }

    public function test_authenticated_user_can_fetch_their_profile()
    {
        $user = User::factory()->create([
            'name' => 'Test User',
        ]);
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/user');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'id' => $user->id,
                    'name' => 'Test User',
                ],
            ]);
    }
}
