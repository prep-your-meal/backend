<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MealPlanApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_meal_plan()
    {
        // Wir schicken einen GET-Request OHNE Token
        $response = $this->getJson('/api/plan');

        // Erwartet: 401 Unauthorized, weil die Sanctum-Middleware blockt
        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_empty_meal_plan()
    {
        // 1. Arrange: User anlegen und über Sanctum einloggen
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        // 2. Act: Den geschützten Endpunkt aufrufen
        $response = $this->getJson('/api/plan');

        // 3. Assert: Da die DB leer ist, erwarten wir 200 OK und ein leeres Array
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'No active meal plan found.',
                'data' => [],
            ]);
    }

    public function test_generate_plan_fails_if_not_enough_recipes()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        // Wir versuchen einen Plan zu generieren, haben aber noch keine 7 Rezepte in der Test-DB
        $response = $this->postJson('/api/plan/generate');

        // Wir erwarten unseren sauberen 400er Fehler aus dem Controller
        $response->assertStatus(400)
            ->assertJson([
                'status' => 'error',
                'message' => 'Not enough available recipes to fulfill the 30-day rule.',
            ]);
    }
}
