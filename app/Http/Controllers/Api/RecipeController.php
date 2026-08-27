<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecipeResource;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    #[OA\Parameter(
        name: 'random',
        in: 'query',
        description: 'If true, returns recipes in a random order instead of chronological',
        required: false,
        schema: new OA\Schema(type: 'boolean', default: false)
    )]
    #[OA\Response(response: 200, description: 'List of recipes')]
    public function index(Request $request): JsonResponse
    {
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

        // Apply random ordering if the 'random' query parameter is true
        // Otherwise, fallback to the default chronological order
        if ($request->boolean('random')) {
            $query->inRandomOrder();
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $recipes = $query->with('ingredients')->paginate($perPage);

        // Wir rufen nur noch die Resource-Collection auf, das Flattening passiert automatisch!
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
        // Load directly from database with ingredients
        $recipe = Recipe::with('ingredients')->where('slug', $slug)->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => new RecipeResource($recipe),
        ]);
    }
}
