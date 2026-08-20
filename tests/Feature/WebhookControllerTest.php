<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class WebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Set all required config values for the WebhookController
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

    public function test_it_successfully_syncs_recipes_ingredients_and_images_from_github()
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
            // NEU: Simuliere die categories.yaml im GitHub Repo
            $zip->addFromString('repo-main/categories.yaml', '
meal_types:
  - breakfast
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
  - "nuts"
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
            $zip->addFromString('repo-main/recipes/images/chicken-curry.webp', 'fake-image-content');

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
        ]);

        $this->assertDatabaseHas('recipes', [
            'slug' => 'chicken-curry',
        ]);

        // Prüfe ob die Bilder kopiert wurden
        $publicImagePath = public_path('recipes/images/chicken-curry.webp');
        $this->assertTrue(File::exists($publicImagePath));

        // NEU: Prüfe ob die categories.yaml korrekt ins Storage entpackt wurde
        $categoriesPath = storage_path('app/recipes/categories.yaml');
        $this->assertTrue(File::exists($categoriesPath));

        // Aufräumen
        File::deleteDirectory(public_path('recipes/images'));
        File::delete($categoriesPath);
    }
}
