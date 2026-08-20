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
        description: 'Retrieves a paginated list of recipes. Supports filtering by category and searching by title. Automatically localized based on Accept-Language header.',
        tags: ['Recipes']
    )]
    // ... (Deine bestehenden Swagger-Parameter für category und search bleiben hier)
    public function index(Request $request): JsonResponse
    {
        // 1. Determine the best language match (defaults to the first array item 'en' if no match)
        $locale = $request->getPreferredLanguage(['en', 'de']);

        $query = Recipe::query();

        // Apply filters (using the new canonical logic)
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
            ->paginate(15);

        // 2. Transform the paginated items to flatten the localized title for the frontend
        $recipes->getCollection()->transform(function ($recipe) use ($locale) {
            // Larastan weiß, dass $recipe->title ein Array ist. Keine Prüfung nötig.
            $recipe->title = $recipe->title[$locale] ?? $recipe->title['en'] ?? $recipe->slug;

            return $recipe;
        });

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
    public function show(string $slug, Request $request): JsonResponse
    {
        // 1. Determine the preferred language
        $locale = $request->getPreferredLanguage(['en', 'de']);

        // 2. Load from cache (the raw model with the JSON array)
        $recipe = Cache::rememberForever("recipe_{$slug}", function () use ($slug) {
            return Recipe::with('ingredients')->where('slug', $slug)->firstOrFail();
        });

        // 3. Convert to array so we don't accidentally mutate the cached object instance
        $responseData = $recipe->toArray();

        // 4. Flatten the localized title
        $responseData['title'] = $recipe->title[$locale] ?? $recipe->title['en'] ?? $recipe->slug;

        return response()->json([
            'status' => 'success',
            'data' => $responseData,
        ]);
    }
}
