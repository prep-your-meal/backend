<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\MealPlan;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
                'message' => 'No active meal plan found for this date range.',
                'data' => [],
            ]);
    }

    public function test_premium_user_can_retrieve_meal_plan_for_specific_date_range()
    {
        // Must be premium to query a future date range
        $user = User::factory()->create(['is_premium' => true]);
        Sanctum::actingAs($user, ['*']);

        $recipe = Recipe::factory()->create();

        // 1. Meal in last week
        $lastWeek = Carbon::now()->subWeek()->startOfWeek()->format('Y-m-d');
        MealPlan::create([
            'user_id' => $user->id,
            'recipe_slug' => $recipe->slug,
            'scheduled_for' => $lastWeek,
            'portions' => 2,
        ]);

        // 2. Meal in this week
        $thisWeek = Carbon::now()->startOfWeek()->format('Y-m-d');
        MealPlan::create([
            'user_id' => $user->id,
            'recipe_slug' => $recipe->slug,
            'scheduled_for' => $thisWeek,
            'portions' => 2,
        ]);

        // 3. Meal in next week
        $nextWeekStart = Carbon::now()->addWeek()->startOfWeek()->format('Y-m-d');
        $nextWeekEnd = Carbon::now()->addWeek()->endOfWeek()->format('Y-m-d');
        MealPlan::create([
            'user_id' => $user->id,
            'recipe_slug' => $recipe->slug,
            'scheduled_for' => $nextWeekStart,
            'portions' => 2,
        ]);

        // Test querying ONLY the next week using query string parameters
        $query = http_build_query([
            'start_date' => $nextWeekStart,
            'end_date' => $nextWeekEnd,
        ]);

        $response = $this->getJson("/plan?{$query}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));

        $this->assertStringStartsWith($nextWeekStart, $response->json('data.0.scheduled_for'));
    }

    public function test_non_premium_user_cannot_retrieve_future_plan()
    {
        $user = User::factory()->create(['is_premium' => false]);
        Sanctum::actingAs($user, ['*']);

        $nextWeekStart = Carbon::now()->addWeek()->startOfWeek()->format('Y-m-d');
        $nextWeekEnd = Carbon::now()->addWeek()->endOfWeek()->format('Y-m-d');

        $query = http_build_query([
            'start_date' => $nextWeekStart,
            'end_date' => $nextWeekEnd,
        ]);

        $response = $this->getJson("/plan?{$query}");

        $response->assertStatus(403)
            ->assertJsonPath('requires_premium', true);
    }

    public function test_generate_plan_fails_if_not_enough_recipes_matching_requirements()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson('/plan/generate');

        $response->assertStatus(400)
            ->assertJson([
                'status' => 'error',
                'message' => 'Not enough available recipes matching your strict dietary requirements.',
            ]);
    }

    public function test_successfully_generates_meal_plan_with_dynamic_default_portions()
    {
        $user = User::factory()->create(['default_portions' => 4]);
        Sanctum::actingAs($user, ['*']);

        Recipe::factory()->count(7)
            ->hasAttached(Ingredient::factory()->count(2), ['amount' => 100])
            ->create();

        // Start on a Monday to ensure all 7 days fit in the current week context
        $monday = Carbon::now()->startOfWeek()->format('Y-m-d');
        $response = $this->postJson('/plan/generate', ['start_date' => $monday]);

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseCount('meal_plans', 7);
        $this->assertDatabaseHas('meal_plans', [
            'user_id' => $user->id,
            'portions' => 4,
        ]);
    }

    public function test_caps_generated_meals_to_remaining_days_of_the_week()
    {
        $user = User::factory()->create(['target_meals_per_week' => 5]);
        Sanctum::actingAs($user, ['*']);

        Recipe::factory()->count(5)->create();

        // Simulate initiating the generation on a Friday in the current week
        $friday = Carbon::now()->startOfWeek()->addDays(4)->format('Y-m-d');

        $response = $this->postJson('/plan/generate', [
            'start_date' => $friday,
        ]);

        $response->assertStatus(200);

        // Only 3 days remaining in the week (Friday, Saturday, Sunday)
        $this->assertDatabaseCount('meal_plans', 3);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_premium_user_can_generate_meal_plan_for_future_start_date()
    {
        $user = User::factory()->create(['target_meals_per_week' => 3, 'is_premium' => true]);
        Sanctum::actingAs($user, ['*']);

        Recipe::factory()->count(5)->create();

        // Start from next week's Monday
        $futureDate = Carbon::now()->addWeek()->startOfWeek()->format('Y-m-d');

        $response = $this->postJson('/plan/generate', [
            'start_date' => $futureDate,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('meal_plans', 3);
    }

    public function test_non_premium_user_cannot_generate_future_plan()
    {
        $user = User::factory()->create(['is_premium' => false]);
        Sanctum::actingAs($user, ['*']);

        $futureDate = Carbon::now()->addWeek()->startOfWeek()->format('Y-m-d');

        $response = $this->postJson('/plan/generate', [
            'start_date' => $futureDate,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('requires_premium', true);
    }

    public function test_premium_user_scoped_deletion_works_for_future_weeks()
    {
        $user = User::factory()->create(['target_meals_per_week' => 2, 'is_premium' => true]);
        Sanctum::actingAs($user, ['*']);

        $recipe = Recipe::factory()->create();
        Recipe::factory()->count(5)->create();

        // Existing plan for today (should remain untouched)
        $today = Carbon::today()->format('Y-m-d');
        MealPlan::create([
            'user_id' => $user->id,
            'recipe_slug' => $recipe->slug,
            'scheduled_for' => $today,
            'portions' => 2,
        ]);

        // Generate plan for next week's Monday (2 days)
        $futureDate = Carbon::now()->addWeek()->startOfWeek()->format('Y-m-d');

        $response = $this->postJson('/plan/generate', [
            'start_date' => $futureDate,
        ]);

        $response->assertStatus(200);

        // Old plan for today must persist
        $this->assertDatabaseHas('meal_plans', [
            'user_id' => $user->id,
            'scheduled_for' => $today,
        ]);

        // Expected entries: 1 old + 2 new = 3
        $this->assertDatabaseCount('meal_plans', 3);
    }

    public function test_respects_target_meals_per_week_preference()
    {
        $user = User::factory()->create(['target_meals_per_week' => 4]);
        Sanctum::actingAs($user, ['*']);

        Recipe::factory()->count(5)->create();

        $monday = Carbon::now()->startOfWeek()->format('Y-m-d');
        $response = $this->postJson('/plan/generate', ['start_date' => $monday]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('meal_plans', 4);
    }

    public function test_hybrid_preference_logic_filters_recipes_correctly()
    {
        $user = User::factory()->create([
            'target_meals_per_week' => 2,
            'dietary_preferences' => ['vegan', 'vegetarian'],
            'fitness_goals' => [],
            'logistics_preferences' => ['quick'],
        ]);
        Sanctum::actingAs($user, ['*']);

        Recipe::factory()->create(['slug' => 'vegan-quick-meal', 'categories' => ['vegan', 'quick']]);
        Recipe::factory()->create(['slug' => 'vegetarian-quick-meal', 'categories' => ['vegetarian', 'quick']]);
        Recipe::factory()->create(['slug' => 'vegan-slow-meal', 'categories' => ['vegan', 'time-consuming']]);
        Recipe::factory()->create(['slug' => 'meat-quick-meal', 'categories' => ['meat', 'quick']]);

        $monday = Carbon::now()->startOfWeek()->format('Y-m-d');
        $response = $this->postJson('/plan/generate', ['start_date' => $monday]);

        $response->assertStatus(200);

        $this->assertDatabaseCount('meal_plans', 2);
        $this->assertDatabaseHas('meal_plans', ['user_id' => $user->id, 'recipe_slug' => 'vegan-quick-meal']);
        $this->assertDatabaseHas('meal_plans', ['user_id' => $user->id, 'recipe_slug' => 'vegetarian-quick-meal']);
    }

    public function test_allergy_blacklist_is_strictly_enforced()
    {
        $user = User::factory()->create([
            'target_meals_per_week' => 2,
            'allergies' => ['nuts', 'shellfish'],
        ]);
        Sanctum::actingAs($user, ['*']);

        Recipe::factory()->create(['slug' => 'safe-recipe-1', 'categories' => ['vegan', 'quick']]);
        Recipe::factory()->create(['slug' => 'safe-recipe-2', 'categories' => ['high-protein']]);
        Recipe::factory()->create(['slug' => 'dangerous-nut-recipe', 'categories' => ['nuts', 'dessert']]);
        Recipe::factory()->create(['slug' => 'dangerous-shellfish-recipe', 'categories' => ['shellfish', 'dinner']]);

        $monday = Carbon::now()->startOfWeek()->format('Y-m-d');
        $response = $this->postJson('/plan/generate', ['start_date' => $monday]);

        $response->assertStatus(200);

        $this->assertDatabaseCount('meal_plans', 2);
        $this->assertDatabaseMissing('meal_plans', ['recipe_slug' => 'dangerous-nut-recipe']);
    }

    public function test_respects_minimize_food_waste_toggle_enabled()
    {
        $user = User::factory()->create([
            'target_meals_per_week' => 3,
            'minimize_food_waste' => true,
        ]);
        Sanctum::actingAs($user, ['*']);

        Recipe::factory()->count(5)->create();

        $monday = Carbon::now()->startOfWeek()->format('Y-m-d');
        $response = $this->postJson('/plan/generate', ['start_date' => $monday]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('meal_plans', 3);
    }

    public function test_user_can_manually_add_a_meal_to_a_specific_date()
    {
        $user = User::factory()->create(['default_portions' => 3]);
        Sanctum::actingAs($user, ['*']);

        $recipe = Recipe::factory()
            ->hasAttached(Ingredient::factory()->count(1), ['amount' => 50])
            ->create(['slug' => 'my-favorite-curry']);
        $date = Carbon::now()->startOfWeek()->format('Y-m-d');

        $response = $this->postJson("/plan/{$date}/add", ['recipe_slug' => $recipe->slug]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('meal_plans', [
            'user_id' => $user->id,
            'scheduled_for' => $date,
            'recipe_slug' => $recipe->slug,
        ]);
    }

    public function test_non_premium_user_cannot_add_meal_to_future_date()
    {
        $user = User::factory()->create(['is_premium' => false]);
        Sanctum::actingAs($user, ['*']);

        $recipe = Recipe::factory()->create();
        $futureDate = Carbon::now()->addWeek()->startOfWeek()->format('Y-m-d');

        $response = $this->postJson("/plan/{$futureDate}/add", ['recipe_slug' => $recipe->slug]);

        $response->assertStatus(403)
            ->assertJsonPath('requires_premium', true);
    }

    public function test_user_can_clear_a_meal_for_a_specific_date()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $date = Carbon::now()->startOfWeek()->format('Y-m-d');
        $recipe = Recipe::factory()->create();

        MealPlan::create([
            'user_id' => $user->id,
            'recipe_slug' => $recipe->slug,
            'scheduled_for' => $date,
            'portions' => 2,
        ]);

        $response = $this->deleteJson("/plan/{$date}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('meal_plans', [
            'user_id' => $user->id,
            'scheduled_for' => $date,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_alternatives()
    {
        $today = Carbon::now()->startOfWeek()->format('Y-m-d');
        $response = $this->getJson("/plan/{$today}/alternatives");
        $response->assertStatus(401);
    }

    public function test_user_can_retrieve_alternatives_for_a_date()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $today = Carbon::now()->startOfWeek()->format('Y-m-d');

        Recipe::factory()->count(3)
            ->hasAttached(Ingredient::factory()->count(1), ['amount' => 100])
            ->create();

        $response = $this->getJson("/plan/{$today}/alternatives");

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_non_premium_user_cannot_retrieve_alternatives_for_future_date()
    {
        $user = User::factory()->create(['is_premium' => false]);
        Sanctum::actingAs($user, ['*']);

        $futureDate = Carbon::now()->addWeek()->startOfWeek()->format('Y-m-d');

        $response = $this->getJson("/plan/{$futureDate}/alternatives");

        $response->assertStatus(403)
            ->assertJsonPath('requires_premium', true);
    }

    public function test_alternatives_prioritizes_food_waste_reduction_when_enabled()
    {
        $user = User::factory()->create(['minimize_food_waste' => true]);
        Sanctum::actingAs($user, ['*']);

        $today = Carbon::now()->startOfWeek()->format('Y-m-d');
        $tomorrow = Carbon::now()->startOfWeek()->addDay()->format('Y-m-d');

        $sharedIngredient = Ingredient::factory()->create(['slug' => 'avocado']);
        $uniqueIngredient = Ingredient::factory()->create(['slug' => 'tofu']);

        $scheduledRecipe = Recipe::factory()->create(['slug' => 'scheduled-recipe']);
        $scheduledRecipe->ingredients()->attach($sharedIngredient, ['amount' => 1]);

        MealPlan::create([
            'user_id' => $user->id,
            'recipe_slug' => $scheduledRecipe->slug,
            'scheduled_for' => $today,
            'portions' => 2,
        ]);

        $recipeWithOverlap = Recipe::factory()->create(['slug' => 'overlapping-salad']);
        $recipeWithOverlap->ingredients()->attach($sharedIngredient, ['amount' => 1]);

        $recipeWithoutOverlap = Recipe::factory()->create(['slug' => 'independent-stirfry']);
        $recipeWithoutOverlap->ingredients()->attach($uniqueIngredient, ['amount' => 1]);

        $response = $this->getJson("/plan/{$tomorrow}/alternatives");
        $response->assertStatus(200);

        $firstRecommendationSlug = $response->json('data.0.slug');
        $this->assertEquals('overlapping-salad', $firstRecommendationSlug);
    }
}
