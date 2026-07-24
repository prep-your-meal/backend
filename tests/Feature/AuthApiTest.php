<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
}
