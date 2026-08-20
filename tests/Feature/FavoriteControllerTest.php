<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoriteControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_cannot_access_favorites()
    {
        $this->getJson('/favorites')->assertStatus(401);

        $response = $this->postJson('/favorites/some-recipe/toggle');
        $this->assertTrue(in_array($response->status(), [401, 404]));
    }

    public function test_user_can_add_a_favorite_recipe()
    {
        $user = User::factory()->create();

        $recipe = Recipe::forceCreate([
            'slug' => 'test-recipe',
            'title' => ['en' => 'Test Recipe'],
            'calories' => 500,
            'default_portions' => 2,
        ]);

        // Request 1: Toggle ON
        $response = $this->actingAs($user, 'sanctum')->postJson("/favorites/{$recipe->slug}/toggle");

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseHas('recipe_user', [
            'user_id' => $user->id,
            'recipe_slug' => $recipe->slug,
        ]);
    }

    public function test_user_can_remove_a_favorite_recipe()
    {
        $user = User::factory()->create();

        $recipe = Recipe::forceCreate([
            'slug' => 'test-recipe',
            'title' => ['en' => 'Test Recipe'],
            'calories' => 500,
            'default_portions' => 2,
        ]);

        // Setup: We simulate that the recipe is already favorited by attaching it directly
        $user->favoriteRecipes()->attach($recipe->slug);

        // Request 2: Toggle OFF
        $response = $this->actingAs($user, 'sanctum')->postJson("/favorites/{$recipe->slug}/toggle");

        $response->assertStatus(200)
            ->assertJson(['status' => 'success']);

        $this->assertDatabaseMissing('recipe_user', [
            'user_id' => $user->id,
            'recipe_slug' => $recipe->slug,
        ]);
    }

    public function test_toggling_non_existent_recipe_returns_404()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/favorites/invalid-slug/toggle');

        $response->assertStatus(404)
            ->assertJson(['status' => 'error']);
    }

    public function test_user_can_list_their_favorite_recipes()
    {
        $user = User::factory()->create();

        $recipe1 = Recipe::forceCreate([
            'slug' => 'favorite-recipe',
            'title' => ['en' => 'Favorite Recipe'],
            'calories' => 400,
        ]);

        $recipe2 = Recipe::forceCreate([
            'slug' => 'other-recipe',
            'title' => ['en' => 'Other Recipe'],
            'calories' => 600,
        ]);

        $user->favoriteRecipes()->attach($recipe1->slug);

        $response = $this->actingAs($user, 'sanctum')->getJson('/favorites');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    '*' => ['slug', 'title'],
                ],
                'meta' => ['current_page', 'last_page', 'total'],
            ]);

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('favorite-recipe', $response->json('data.0.slug'));
    }
}
