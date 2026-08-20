<?php

namespace Tests\Feature;

use App\Models\CustomShoppingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomShoppingItemApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_add_custom_item_to_shopping_list(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/shopping-list/custom', [
            'name' => 'ESN Athlete Stack',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'ESN Athlete Stack')
            ->assertJsonPath('data.is_checked', false);

        $this->assertDatabaseHas('custom_shopping_items', [
            'user_id' => $user->id,
            'name' => 'ESN Athlete Stack',
            'is_checked' => false,
        ]);
    }

    public function test_user_can_toggle_custom_item_status(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $item = CustomShoppingItem::create([
            'user_id' => $user->id,
            'name' => 'Omega-3 Kapseln',
            'is_checked' => false,
        ]);

        $response = $this->putJson("/shopping-list/custom/{$item->id}/toggle");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_checked', true);

        $this->assertDatabaseHas('custom_shopping_items', [
            'id' => $item->id,
            'is_checked' => true,
        ]);
    }

    public function test_user_can_delete_specific_custom_item(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $item = CustomShoppingItem::create([
            'user_id' => $user->id,
            'name' => 'Creatine',
        ]);

        $response = $this->deleteJson("/shopping-list/custom/{$item->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('custom_shopping_items', [
            'id' => $item->id,
        ]);
    }

    public function test_user_can_clear_all_completed_items(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $completedItem = CustomShoppingItem::create([
            'user_id' => $user->id,
            'name' => 'Spülmaschinentabs',
            'is_checked' => true,
        ]);

        $pendingItem = CustomShoppingItem::create([
            'user_id' => $user->id,
            'name' => 'Müllbeutel',
            'is_checked' => false,
        ]);

        $response = $this->deleteJson('/shopping-list/custom/completed');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseMissing('custom_shopping_items', ['id' => $completedItem->id]);
        $this->assertDatabaseHas('custom_shopping_items', ['id' => $pendingItem->id]);
    }

    public function test_custom_items_are_included_in_main_shopping_list_response(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        CustomShoppingItem::create([
            'user_id' => $user->id,
            'name' => 'Vitamin D3 with K2',
            'is_checked' => false,
        ]);

        $response = $this->getJson('/shopping-list');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'recipes', // The generated ones
                    'custom_items' => [ // The new manual ones
                        '*' => ['id', 'name', 'is_checked'],
                    ],
                ],
            ])
            ->assertJsonPath('data.custom_items.0.name', 'Vitamin D3 with K2');
    }
}
