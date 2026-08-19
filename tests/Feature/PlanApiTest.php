<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanApiTest extends TestCase
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

        // Updated error message to match the new controller logic
        $response->assertStatus(400)
            ->assertJson([
                'status' => 'error',
                'message' => 'Not enough available recipes in the database.',
            ]);
    }

    public function test_successfully_generates_meal_plan_with_default_portions()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        // Create 7 dummy recipes to satisfy the default 7-day requirement
        Recipe::factory()->count(7)->create();

        $response = $this->postJson('/plan/generate');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => '7-day smart meal plan successfully generated.',
            ]);

        // Verify database contains exactly 7 meal plans
        $this->assertDatabaseCount('meal_plans', 7);

        // Verify that the portions are automatically set to 3 for the family
        $this->assertDatabaseHas('meal_plans', [
            'user_id' => $user->id,
            'portions' => 3,
        ]);
    }

    public function test_respects_target_meals_per_week_preference()
    {
        // Create a user who only wants 4 meals planned per week via the wizard
        $user = User::factory()->create([
            'target_meals_per_week' => 4,
        ]);
        Sanctum::actingAs($user, ['*']);

        // Create enough recipes in the database
        Recipe::factory()->count(5)->create();

        $response = $this->postJson('/plan/generate');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => '4-day smart meal plan successfully generated.',
            ]);

        // Verify the system only scheduled 4 meals, respecting the user's settings
        $this->assertDatabaseCount('meal_plans', 4);
    }
}
