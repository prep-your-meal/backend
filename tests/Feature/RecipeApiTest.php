<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_retrieve_single_recipe()
    {
        // Create a recipe with ingredients to test eager loading and resource transformation
        $recipe = Recipe::factory()
            ->hasAttached(
                Ingredient::factory()->count(3),
                ['amount' => '200'] // Provide default pivot data here
            )
            ->create([
                'slug' => 'test-recipe',
            ]);

        $response = $this->getJson('/recipes/test-recipe');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.slug', 'test-recipe');

        // Ensure ingredients are loaded in the response
        $this->assertCount(3, $response->json('data.ingredients'));

        // Validate that the IngredientResource correctly flattened the pivot data
        $firstIngredient = $response->json('data.ingredients.0');
        $this->assertArrayHasKey('amount', $firstIngredient);
        $this->assertEquals(200, $firstIngredient['amount']);
        $this->assertArrayNotHasKey('pivot', $firstIngredient);
    }

    public function test_returns_404_for_invalid_recipe()
    {
        $response = $this->getJson('/recipes/invalid-slug');

        $response->assertStatus(404);
    }

    public function test_can_retrieve_paginated_list_of_recipes()
    {
        // Create 20 recipes
        Recipe::factory()->count(20)->create();

        $response = $this->getJson('/recipes');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(15, 'data') // Assuming pagination is set to 15
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_can_filter_recipes_by_category()
    {
        // Create specific recipes to test the JSON column filtering
        Recipe::factory()->count(3)->create([
            'categories' => ['meat', 'slow'],
        ]);
        Recipe::factory()->count(2)->create([
            'categories' => ['vegan', 'quick'],
        ]);

        $response = $this->getJson('/recipes?category=vegan');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data') // Only the 2 vegan recipes should be returned
            ->assertJsonPath('meta.total', 2);
    }

    public function test_can_search_recipes_by_title()
    {
        // Create specific recipes to test the text search using the correct 'title' column
        Recipe::factory()->create(['title' => 'Spicy Chicken Curry']);
        Recipe::factory()->create(['title' => 'Vegan Beef Burger']);
        Recipe::factory()->create(['title' => 'Chicken Salad']);

        // Search for "Chicken"
        $response = $this->getJson('/recipes?search=Chicken');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data'); // Should find "Spicy Chicken Curry" and "Chicken Salad"
    }
}
