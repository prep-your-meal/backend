<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,

            // Preferences
            'target_meals_per_week' => $this->target_meals_per_week,
            'default_portions' => $this->default_portions ?? 2,
            'dietary_preferences' => $this->dietary_preferences ?? [],
            'fitness_goals' => $this->fitness_goals ?? [],
            'logistics_preferences' => $this->logistics_preferences ?? [],
            'allergies' => $this->allergies ?? [],
            'minimize_food_waste' => (bool) ($this->minimize_food_waste ?? true),
        ];
    }
}
