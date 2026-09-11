<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
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
                'user' => [
                    'id',
                    'name',
                    'email',
                    'target_meals_per_week',
                    'default_portions',
                    'dietary_preferences',
                    'fitness_goals',
                    'logistics_preferences',
                    'allergies',
                    'minimize_food_waste',
                ],
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

        // Wir erwarten jetzt nur noch status und message, da kein Auto-Login mehr stattfindet
        $response->assertStatus(201)
            ->assertJsonStructure([
                'status',
                'message',
            ])
            ->assertJsonFragment([
                'status' => 'success',
                'message' => 'User registered. Please check your emails for the verification link.',
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
            ->assertJsonStructure([
                'status',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'target_meals_per_week',
                    'default_portions',
                    'dietary_preferences',
                    'fitness_goals',
                    'logistics_preferences',
                    'allergies',
                    'minimize_food_waste',
                ],
            ])
            ->assertJsonFragment([
                'id' => $user->id,
                'name' => 'Test User',
            ])
            ->assertJsonMissing(['created_at', 'updated_at']); // Proves the resource strips timestamps
    }

    public function test_user_can_request_password_reset_link()
    {
        $user = User::factory()->create([
            'email' => 'reset@prepyourmeal.local',
        ]);

        $response = $this->postJson('/auth/forgot-password', [
            'email' => 'reset@prepyourmeal.local',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);
    }

    public function test_user_can_reset_password_with_valid_token()
    {
        $user = User::factory()->create([
            'email' => 'reset@prepyourmeal.local',
            'password' => Hash::make('oldpassword123'),
        ]);

        // Generate a valid reset token directly via Laravel's Password Broker
        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/auth/reset-password', [
            'email' => 'reset@prepyourmeal.local',
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        // Verify the password was actually changed in the database
        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
    }

    public function test_login_fails_if_email_is_unverified()
    {
        $user = User::factory()->create([
            'email' => 'unverified@prepyourmeal.local',
            'password' => Hash::make('secret123'),
            'email_verified_at' => null, // Explicitly unverified
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => 'unverified@prepyourmeal.local',
            'password' => 'secret123',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'message' => 'Please verify your email address before logging in.',
                'needs_verification' => true,
            ]);
    }

    public function test_user_can_update_profile_name_without_losing_verification()
    {
        $user = User::factory()->create([
            'email' => 'stable@example.com',
            'email_verified_at' => now(),
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->putJson('/user/profile', [
            'name' => 'New Name',
            'email' => 'stable@example.com', // Unchanged
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Profile updated.']);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
        ]);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_user_loses_verification_when_updating_email()
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'email_verified_at' => now(),
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->putJson('/user/profile', [
            'name' => $user->name,
            'email' => 'new@example.com', // Changed
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Profile updated. Please verify your new email.']);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'new@example.com',
            'email_verified_at' => null, // Must be null now
        ]);
    }
}
