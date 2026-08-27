<?php

namespace App\Http\Resources;

use App\Models\Ingredient;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Recipe
 *
 * @property Collection<int, Ingredient> $ingredients
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
        $locale = $request->getPreferredLanguage(['en', 'de']);

        return [
            'slug' => $this->slug,

            'title' => $this->title[$locale] ?? $this->title['en'] ?? $this->slug,
            'instructions' => $this->instructions[$locale] ?? $this->instructions['en'] ?? null,

            'image' => $this->image ? url($this->image) : null,
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

            'ingredients' => $this->whenLoaded('ingredients', function () use ($locale) {
                return $this->ingredients->map(function ($ingredient) use ($locale) {

                    /** @var array<string, string>|string $rawName */
                    $rawName = $ingredient->name;

                    $localizedName = is_array($rawName)
                        ? ($rawName[$locale] ?? $rawName['en'] ?? $ingredient->slug)
                        : $rawName;

                    return [
                        'slug' => $ingredient->slug,
                        'name' => $localizedName,
                        'unit' => $ingredient->unit,
                        'category' => $ingredient->category,
                        'amount' => $ingredient->pivot->amount ?? 0,
                    ];
                });
            }),
        ];
    }
}
