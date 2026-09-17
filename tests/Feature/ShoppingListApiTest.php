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

    public function test_shopping_list_aggregates_ingredients_for_current_week()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $ingredient = Ingredient::factory()->create([
            'slug' => 'tomato',
            'name' => 'Tomato',
            'category' => 'Vegetables',
            'unit' => 'g',
        ]);

        $recipe = Recipe::factory()->create(['default_portions' => 2]);
        $recipe->ingredients()->attach($ingredient, ['amount' => 100]);

        // Schedule meal for the current week (Target: 4 portions. Base: 2. Multiplier: 2 -> 200g)
        $thisWeek = Carbon::now()->startOfWeek()->format('Y-m-d');
        MealPlan::create([
            'user_id' => $user->id,
            'recipe_slug' => $recipe->slug,
            'scheduled_for' => $thisWeek,
            'portions' => 4,
        ]);

        $response = $this->getJson('/shopping-list');

        $response->assertStatus(200);
        $this->assertEquals(200, $response->json('data.recipes.Vegetables.0.total_amount'));
    }

    public function test_shopping_list_aggregates_ingredients_by_custom_date_range()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $ingredient = Ingredient::factory()->create([
            'slug' => 'tomato',
            'name' => 'Tomato',
            'category' => 'Vegetables',
            'unit' => 'g',
        ]);

        $recipe = Recipe::factory()->create(['default_portions' => 2]);
        $recipe->ingredients()->attach($ingredient, ['amount' => 100]);

        // Schedule meal for next week
        $nextWeekStart = Carbon::now()->addWeek()->startOfWeek()->format('Y-m-d');
        $nextWeekEnd = Carbon::now()->addWeek()->endOfWeek()->format('Y-m-d');

        MealPlan::create([
            'user_id' => $user->id,
            'recipe_slug' => $recipe->slug,
            'scheduled_for' => $nextWeekStart,
            'portions' => 2, // Multiplier 1 -> 100g
        ]);

        $response = $this->getJson("/shopping-list?start_date={$nextWeekStart}&end_date={$nextWeekEnd}");

        $response->assertStatus(200);
        $this->assertEquals(100, $response->json('data.recipes.Vegetables.0.total_amount'));
    }
}
