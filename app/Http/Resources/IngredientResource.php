<?php

namespace App\Http\Resources;

use App\Models\Ingredient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ingredient
 */
class IngredientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'unit' => $this->unit,
            'category' => $this->category,
            // Extract the amount directly from the pivot table if it is loaded
            'amount' => $this->whenPivotLoaded('ingredient_recipe', function () {
                /** @phpstan-ignore-next-line */
                return (float) $this->pivot->amount;
            }),
        ];
    }
}
