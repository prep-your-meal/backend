<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShoppingListApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_shopping_list()
    {
        // GET-Request ohne Authentifizierung
        $response = $this->getJson('/api/shopping-list');

        // Erwartet: 401 Unauthorized
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_empty_shopping_list()
    {
        // 1. Arrange: User anlegen und via Sanctum einloggen
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        // 2. Act: Geschützten Endpunkt aufrufen
        $response = $this->getJson('/api/shopping-list');

        // 3. Assert: 200 OK und ein leeres Array für 'data'
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [],
            ]);
    }
}
