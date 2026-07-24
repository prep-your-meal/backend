<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    // RefreshDatabase sorgt dafür, dass die Test-Datenbank nach jedem Test sauber geleert wird
    use RefreshDatabase;

    public function test_user_can_login_with_correct_credentials()
    {
        // 1. Arrange: Einen Test-User anlegen
        $user = User::factory()->create([
            'email' => 'test@prepyourmeal.local',
            'password' => Hash::make('secret123'),
        ]);

        // 2. Act: Den Login-Endpunkt aufrufen
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@prepyourmeal.local',
            'password' => 'secret123',
        ]);

        // 3. Assert: Prüfen, ob die Antwort erfolgreich ist und ein Token enthält
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

        $response = $this->postJson('/api/auth/login', [
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
