<?php

namespace Tests\Feature;

use Tests\TestCase;

class MetaControllerTest extends TestCase
{
    public function test_public_can_fetch_meta_categories()
    {
        // This endpoint should be public (no auth required) so the frontend
        // can use it even during the registration/onboarding process
        $response = $this->getJson('/meta/categories');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'meal_types',       // <-- NEW
                    'diets',
                    'fitness_profiles', // <-- RENAMED
                    'logistics',
                    'allergies',
                ],
            ]);

        // Check if our specific arrays are returned correctly
        $data = $response->json('data');
        $this->assertContains('vegan', $data['diets']);
        $this->assertContains('high-protein', $data['fitness_profiles']); // <-- RENAMED
        $this->assertContains('family-friendly', $data['logistics']);
        $this->assertContains('nuts', $data['allergies']);
    }
}
