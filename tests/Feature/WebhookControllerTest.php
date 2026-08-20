<?php

namespace Tests\Feature;

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

        // Alle benötigten Config-Werte für den WebhookController setzen
        config([
            'services.github.sync_secret' => 'test-secret-key',
            'services.github.repo' => 'test/repo',
            'services.github.branch' => 'main',
            'services.github.token' => 'dummy-token',
        ]);
    }

    public function test_it_rejects_unauthorized_requests()
    {
        $responseNoHeader = $this->postJson('/webhooks/github');
        $responseNoHeader->assertStatus(401)
            ->assertJson(['status' => 'error']);
    }

    public function test_it_rejects_requests_with_invalid_token()
    {
        $response = $this->postJson('/webhooks/github', [], [
            'X-PYM-SYNC-TOKEN' => 'wrong-token',
        ]);

        $response->assertStatus(401)
            ->assertJson(['status' => 'error']);
    }

    public function test_it_successfully_syncs_recipes_and_ingredients_from_github()
    {
        $tempZipPath = tempnam(sys_get_temp_dir(), 'repo').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $zip->addFromString('repo-main/ingredients.yaml', '
chicken_breast:
  en: "Chicken breast"
  de: "Hähnchenbrust"
  unit: "g"
  category: "meat"
');

            $zip->addFromString('repo-main/recipes/en/chicken-curry.md', '---
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

        Http::fake([
            'api.github.com/*' => Http::response($zipContent, 200),
        ]);

        $response = $this->postJson('/webhooks/github', [], [
            'X-PYM-SYNC-TOKEN' => 'test-secret-key',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);

        $this->assertDatabaseHas('ingredients', [
            'slug' => 'chicken_breast',
            'name' => 'Chicken breast',
        ]);

        // Prüfe Basis-Daten (ohne JSON Felder, um SQLite Kompatibilität zu wahren)
        $this->assertDatabaseHas('recipes', [
            'slug' => 'chicken-curry',
            'calories' => 550,
            'protein_g' => 45,
        ]);

        // JSON-Felder sicher über das Model abfragen
        $recipe = Recipe::where('slug', 'chicken-curry')->first();
        $this->assertNotNull($recipe);
        $this->assertEquals('Chicken Curry', $recipe->title['en']);
        $this->assertStringContainsString('Cook the chicken.', $recipe->instructions['en']);

        $this->assertCount(1, $recipe->ingredients);
        $pivotIngredient = $recipe->ingredients->first();
        $this->assertEquals('chicken_breast', $pivotIngredient->slug);
        $this->assertEquals(400, $pivotIngredient->pivot->amount);
    }
}
