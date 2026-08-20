<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        description: 'Retrieves a paginated list of recipes. Supports filtering by category.',
        security: [['bearerAuth' => []]],
        tags: ['Recipes']
    )]
    #[OA\Parameter(
        name: 'category',
        in: 'query',
        required: false,
        description: 'Filter recipes by a specific category (e.g., vegan, quick)',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Parameter(
        name: 'search',
        in: 'query',
        required: false,
        description: 'Search recipes by their name',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(response: 200, description: 'List of recipes retrieved successfully')]
    public function index(Request $request): JsonResponse
    {
        $query = Recipe::query();

        // Apply category filter if provided
        $query->when($request->query('category'), function ($q, $category) {
            $q->whereJsonContains('categories', $category);
        });

        // Apply search filter if provided (case-insensitive partial match on the title)
        $query->when($request->query('search'), function ($q, $search) {
            $q->where('title', 'like', '%'.$search.'%');
        });

        // Eager load ingredients and paginate
        $recipes = $query->with('ingredients')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'status' => 'success',
            'data' => $recipes->items(),
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
        description: 'Retrieves a single recipe by its slug, including all related ingredients.',
        security: [['bearerAuth' => []]],
        tags: ['Recipes']
    )]
    #[OA\Parameter(
        name: 'slug',
        in: 'path',
        required: true,
        description: 'The unique slug of the recipe',
        schema: new OA\Schema(type: 'string')
    )]
    #[OA\Response(response: 200, description: 'Recipe details retrieved successfully')]
    #[OA\Response(response: 404, description: 'Recipe not found')]
    public function show(string $slug): JsonResponse
    {
        // Cache the recipe indefinitely. The RecipeObserver handles cache invalidation.
        $recipe = Cache::rememberForever("recipe_{$slug}", function () use ($slug) {
            return Recipe::with('ingredients')->where('slug', $slug)->firstOrFail();
        });

        return response()->json([
            'status' => 'success',
            'data' => $recipe,
        ]);
    }
}
