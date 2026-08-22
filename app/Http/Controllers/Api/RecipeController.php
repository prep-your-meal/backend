<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecipeResource;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenApi\Attributes as OA;

class RecipeController extends Controller
{
    #[OA\Get(
        path: '/recipes',
        summary: 'List all recipes',
        description: 'Retrieves a paginated list of recipes. Supports filtering by category and searching by title. Automatically localized based on Accept-Language header.',
        tags: ['Recipes']
    )]
    #[OA\Parameter(
        name: 'page',
        in: 'query',
        description: 'The page number to retrieve',
        required: false,
        schema: new OA\Schema(type: 'integer', default: 1)
    )]
    #[OA\Parameter(
        name: 'per_page',
        in: 'query',
        description: 'Number of items per page',
        required: false,
        schema: new OA\Schema(type: 'integer', default: 15)
    )]
    #[OA\Parameter(
        name: 'category',
        in: 'query',
        description: 'Filter recipes by a specific category (e.g., diet, meal type, fitness goal)',
        required: false,
        schema: new OA\Schema(
            type: 'string',
            enum: [
                // Meal Types
                'breakfast', 'lunch', 'dinner', 'snack',
                // Diets
                'vegan', 'vegetarian', 'keto', 'low-carb', 'gluten-free', 'dairy-free',
                // Fitness Profiles
                'high-protein', 'bulking', 'cutting', 'balanced',
                // Logistics
                'meal-prep-friendly', 'quick', 'one-pot', 'family-friendly',
            ]
        )
    )]
    #[OA\Parameter(
        name: 'search',
        in: 'query',
        description: 'Search recipes by a title keyword',
        required: false,
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(response: 200, description: 'List of recipes')]
    public function index(Request $request): JsonResponse
    {
        // 1. Determine the best language match (defaults to the first array item 'en' if no match)
        $locale = $request->getPreferredLanguage(['en', 'de']);

        // Dynamic pagination parameter
        $perPage = $request->input('per_page', 15);

        $query = Recipe::query();

        // Apply filters (using the canonical logic)
        $query->when($request->query('category'), function ($q, $category) {
            $q->whereJsonContains('categories', $category);
        });

        $query->when($request->query('search'), function ($q, $search) {
            // Note: In a JSON column, searching via LIKE is database dependent.
            // For MariaDB/MySQL, this string-based LIKE search on the JSON payload usually works fine
            // to find a partial match in either language.
            $q->where('title', 'like', '%'.$search.'%');
        });

        $recipes = $query->with('ingredients')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // 2. Transform the paginated items to flatten the localized title for the frontend
        $recipes->getCollection()->transform(function ($recipe) use ($locale) {
            // Mutate the title on the model instance before passing it to the resource
            $recipe->title = $recipe->title[$locale] ?? $recipe->title['en'] ?? $recipe->slug;

            return $recipe;
        });

        // 3. Return the custom JSON structure using the RecipeResource collection to format the items
        return response()->json([
            'status' => 'success',
            'data' => RecipeResource::collection($recipes->getCollection()),
            'meta' => [
                'current_page' => $recipes->currentPage(),
                'last_page' => $recipes->lastPage(),
                'total' => $recipes->total(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/recipes/{slug}',
        summary: 'Get details of a specific recipe',
        description: 'Retrieves a single recipe by its canonical slug. Automatically localized based on Accept-Language header.',
        tags: ['Recipes']
    )]
    #[OA\Parameter(
        name: 'slug',
        in: 'path',
        required: true,
        description: 'The canonical slug of the recipe (filename without extension)',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(response: 200, description: 'Recipe details')]
    #[OA\Response(response: 404, description: 'Recipe not found')]
    public function show(string $slug, Request $request): JsonResponse
    {
        // 1. Determine the preferred language
        $locale = $request->getPreferredLanguage(['en', 'de']);

        // 2. Load from cache (the raw model with the JSON array and relationships)
        $recipe = Cache::rememberForever("recipe_{$slug}", function () use ($slug) {
            return Recipe::with('ingredients')->where('slug', $slug)->firstOrFail();
        });

        // 3. Clone the model so we do not accidentally mutate the cached object instance in memory
        $clonedRecipe = clone $recipe;

        // 4. Flatten the localized title
        $clonedRecipe->title = $clonedRecipe->title[$locale] ?? $clonedRecipe->title['en'] ?? $clonedRecipe->slug;

        // 5. Return the JSON response wrapping the model in our new RecipeResource
        return response()->json([
            'status' => 'success',
            'data' => new RecipeResource($clonedRecipe),
        ]);
    }
}
