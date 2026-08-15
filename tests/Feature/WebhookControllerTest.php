<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class WebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set configuration value directly for testing
        config(['services.github.sync_secret' => 'test-secret-key']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Test that the webhook rejects requests with missing or invalid tokens.
     */
    public function test_it_rejects_unauthorized_requests()
    {
        $responseNoHeader = $this->postJson('/webhooks/github');
        $responseNoHeader->assertStatus(401)
            ->assertJson(['status' => 'error']);
    }

    /**
     * Test that the webhook rejects requests with an invalid token.
     */
    public function test_it_rejects_requests_with_invalid_token()
    {
        $response = $this->postJson('/webhooks/github', [], [
            'X-PYM-SYNC-TOKEN' => 'wrong-token',
        ]);

        $response->assertStatus(401)
            ->assertJson(['status' => 'error']);
    }

    /**
     * Test that the webhook successfully downloads, parses, and syncs recipes and master ingredients.
     */
    public function test_it_successfully_syncs_recipes_and_ingredients_from_github()
    {
        // 1. Create a temporary ZIP file mimicking the GitHub repository structure
        $tempZipPath = tempnam(sys_get_temp_dir(), 'repo').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            // Add master ingredients registry at root of the repo structure
            $zip->addFromString('repo-main/ingredients.yaml', '
chicken_breast:
  en: "Chicken breast"
  de: "Hähnchenbrust"
  unit: "g"
  category: "meat"
');

            // Add a test recipe in the English folder
            $zip->addFromString('repo-main/recipes/en/chicken-curry.md', '---
slug: "chicken-curry"
title: "Chicken Curry"
image: "recipes/images/chicken-curry.webp"
prep_time: 15
cook_time: 25
default_portions: 2
categories:
  - "dinner"
  - "high-protein"
nutrition_per_portion:
  calories: 550
  protein_g: 45
  carbs_g: 20
  fat_g: 22
ingredients:
  - name: "Chicken breast"
    amount: 400
    unit: "g"
---

## Preparation
1. Cook the chicken.
');
            $zip->close();
        }

        $zipContent = file_get_contents($tempZipPath);
        unlink($tempZipPath);

        // 2. Mock the external GitHub API zipball endpoint using Http::fake()
        Http::fake([
            'api.github.com/*' => Http::response($zipContent, 200),
        ]);

        // 3. Fire the request with the valid sync token header
        $response = $this->postJson('/webhooks/github', [], [
            'X-PYM-SYNC-TOKEN' => 'test-secret-key',
        ]);

        // 4. Assert response is successful
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        // 5. Verify master ingredients were synced correctly
        $this->assertDatabaseHas('ingredients', [
            'slug' => 'chicken_breast',
            'name' => 'Chicken breast',
            'unit' => 'g',
            'category' => 'meat',
        ]);

        // 6. Verify recipe was synced correctly with its nutritional values
        $this->assertDatabaseHas('recipes', [
            'slug' => 'chicken-curry',
            'title' => 'Chicken Curry',
            'calories' => 550,
            'protein_g' => 45,
        ]);

        // 7. Verify the pivot relation between recipe and ingredient including amount
        $recipe = Recipe::where('slug', 'chicken-curry')->first();
        $this->assertNotNull($recipe);
        $this->assertCount(1, $recipe->ingredients);

        $pivotIngredient = $recipe->ingredients->first();
        $this->assertEquals('chicken_breast', $pivotIngredient->slug);
        $this->assertEquals(400, $pivotIngredient->pivot->amount);
    }
}
