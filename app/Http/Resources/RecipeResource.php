<?php

namespace App\Http\Resources;

use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Recipe
 */
class RecipeResource extends JsonResource
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
            'title' => $this->title,
            'instructions' => $this->instructions,
            'image' => $this->image,
            'prep_time' => $this->prep_time,
            'cook_time' => $this->cook_time,
            'default_portions' => $this->default_portions,
            'categories' => $this->categories,
            'nutrition' => [
                'calories' => $this->calories,
                'protein_g' => $this->protein_g,
                'carbs_g' => $this->carbs_g,
                'fat_g' => $this->fat_g,
            ],
            // Map the related ingredients through their own resource
            'ingredients' => IngredientResource::collection($this->whenLoaded('ingredients')),
        ];
    }
}
