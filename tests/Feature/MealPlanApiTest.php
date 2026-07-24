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
        $response = $this->getJson('/plan');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_access_empty_meal_plan()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/plan');

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

        $response = $this->postJson('/plan/generate');

        $response->assertStatus(400)
            ->assertJson([
                'status' => 'error',
                'message' => 'Not enough available recipes to fulfill the 30-day rule.',
            ]);
    }
}
