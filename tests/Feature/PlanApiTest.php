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

    public function test_generate_plan_fails_if_not_enough_recipes_matching_requirements()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/plan/generate');

        // Updated error message to match the new strict allergy fallback logic
        $response->assertStatus(400)
            ->assertJson([
                'status' => 'error',
                'message' => 'Not enough available recipes matching your strict dietary requirements.',
            ]);
    }

    public function test_successfully_generates_meal_plan_with_dynamic_default_portions()
    {
        // Explicitly set default portions to prove the hardcoded value is gone
        $user = User::factory()->create([
            'default_portions' => 4,
        ]);
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

        // Verify that the portions are dynamically set based on user preferences (4)
        $this->assertDatabaseHas('meal_plans', [
            'user_id' => $user->id,
            'portions' => 4,
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

    public function test_hybrid_preference_logic_filters_recipes_correctly()
    {
        // 1. Create a user with specific hybrid preferences
        // Diet: vegan OR vegetarian
        // Logistics: MUST be quick
        $user = User::factory()->create([
            'target_meals_per_week' => 2,
            'dietary_preferences' => ['vegan', 'vegetarian'],
            'fitness_goals' => [], // Empty, should be ignored
            'logistics_preferences' => ['quick'],
        ]);
        Sanctum::actingAs($user, ['*']);

        // 2. Create matching recipes (Valid)
        Recipe::factory()->create([
            'slug' => 'vegan-quick-meal',
            'categories' => ['vegan', 'quick'],
        ]);
        Recipe::factory()->create([
            'slug' => 'vegetarian-quick-meal',
            'categories' => ['vegetarian', 'quick'],
        ]);

        // 3. Create trap recipes (Invalid)
        Recipe::factory()->create([
            'slug' => 'vegan-slow-meal',
            'categories' => ['vegan', 'time-consuming'], // Fails logistics (not quick)
        ]);
        Recipe::factory()->create([
            'slug' => 'meat-quick-meal',
            'categories' => ['meat', 'quick'], // Fails diet (neither vegan nor vegetarian)
        ]);

        // 4. Generate the plan
        $response = $this->postJson('/plan/generate');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        // 5. Verify that exactly 2 meals were planned
        $this->assertDatabaseCount('meal_plans', 2);

        // 6. Verify that ONLY the matching recipes were selected
        $this->assertDatabaseHas('meal_plans', [
            'user_id' => $user->id,
            'recipe_slug' => 'vegan-quick-meal',
        ]);
        $this->assertDatabaseHas('meal_plans', [
            'user_id' => $user->id,
            'recipe_slug' => 'vegetarian-quick-meal',
        ]);

        // Ensure the trap recipes were NOT selected
        $this->assertDatabaseMissing('meal_plans', [
            'user_id' => $user->id,
            'recipe_slug' => 'vegan-slow-meal',
        ]);
    }

    public function test_allergy_blacklist_is_strictly_enforced()
    {
        $user = User::factory()->create([
            'target_meals_per_week' => 2,
            'allergies' => ['nuts', 'shellfish'],
        ]);
        Sanctum::actingAs($user, ['*']);

        // Safe recipes
        Recipe::factory()->create([
            'slug' => 'safe-recipe-1',
            'categories' => ['vegan', 'quick'],
        ]);
        Recipe::factory()->create([
            'slug' => 'safe-recipe-2',
            'categories' => ['high-protein'],
        ]);

        // Dangerous recipes containing allergens
        Recipe::factory()->create([
            'slug' => 'dangerous-nut-recipe',
            'categories' => ['nuts', 'dessert'],
        ]);
        Recipe::factory()->create([
            'slug' => 'dangerous-shellfish-recipe',
            'categories' => ['shellfish', 'dinner'],
        ]);

        $response = $this->postJson('/plan/generate');

        $response->assertStatus(200);

        // Ensure only the two safe recipes were selected
        $this->assertDatabaseCount('meal_plans', 2);
        
        $this->assertDatabaseMissing('meal_plans', [
            'user_id' => $user->id,
            'recipe_slug' => 'dangerous-nut-recipe',
        ]);
        $this->assertDatabaseMissing('meal_plans', [
            'user_id' => $user->id,
            'recipe_slug' => 'dangerous-shellfish-recipe',
        ]);
    }

    public function test_respects_minimize_food_waste_toggle_enabled()
    {
        $user = User::factory()->create([
            'target_meals_per_week' => 3,
            'minimize_food_waste' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        Recipe::factory()->count(5)->create();

        $response = $this->postJson('/plan/generate');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseCount('meal_plans', 3);
    }

    public function test_skips_food_waste_optimization_when_disabled()
    {
        $user = User::factory()->create([
            'target_meals_per_week' => 3,
            'minimize_food_waste' => false, // Explicitly turned off
        ]);
        Sanctum::actingAs($user, ['*']);

        Recipe::factory()->count(5)->create();

        $response = $this->postJson('/plan/generate');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        // Should still generate the requested number of meals via random padding
        $this->assertDatabaseCount('meal_plans', 3);
    }
}