<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecipeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_retrieve_single_recipe()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        // Create a recipe with ingredients to test eager loading
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
    }

    public function test_returns_404_for_invalid_recipe()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/recipes/invalid-slug');

        $response->assertStatus(404);
    }
}
