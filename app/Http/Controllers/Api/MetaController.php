<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use OpenApi\Attributes as OA;
use Symfony\Component\Yaml\Yaml;

class MetaController extends Controller
{
    /**
     * Central helper to load categories from cache or the YAML file.
     * Also used by the UserPreferenceController for validation.
     */
    public static function getCategoriesSchema(): array
    {
        return Cache::rememberForever('pym_categories_schema', function () {
            $path = storage_path('app/recipes/categories.yaml');

            if (! File::exists($path)) {
                // Fallback in case the webhook has never run on a fresh deployment
                return [
                    'meal_types' => ['breakfast', 'lunch', 'dinner', 'snack'],
                    'diets' => ['vegan', 'vegetarian', 'keto', 'low-carb', 'gluten-free', 'dairy-free'],
                    'fitness_profiles' => ['high-protein', 'bulking', 'cutting', 'balanced'],
                    'logistics' => ['meal-prep-friendly', 'quick', 'one-pot', 'family-friendly'],
                    'allergies' => ['nuts', 'shellfish', 'soy', 'eggs', 'lactose', 'gluten'],
                ];
            }

            return Yaml::parseFile($path);
        });
    }

    #[OA\Get(
        path: '/meta/categories',
        summary: 'Get all available preference categories',
        tags: ['System']
    )]
    #[OA\Response(response: 200, description: 'List of available categories')]
    public function categories(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => self::getCategoriesSchema(),
        ]);
    }
}
