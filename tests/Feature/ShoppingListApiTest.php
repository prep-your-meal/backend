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
        $response = $this->getJson('/shopping-list');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_empty_shopping_list()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/shopping-list');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'recipes' => [],
                    'custom_items' => [],
                ],
            ]);
    }
}
