<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecipeResource;
use App\Models\Recipe;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class FavoriteController extends Controller
{
    #[OA\Get(
        path: '/favorites',
        summary: 'List user favorite recipes',
        security: [['bearerAuth' => []]],
        tags: ['Favorites']
    )]
    #[OA\Response(response: 200, description: 'List of favorite recipes')]
    public function index(Request $request)
    {
        // 1. Determine the best language match
        $locale = $request->getPreferredLanguage(['en', 'de']);

        // 2. Fetch the current user's favorite recipes with ingredients, paginated for the PWA frontend
        $favorites = $request->user()->favoriteRecipes()->with('ingredients')->paginate(15);

        // 3. Transform the paginated items to flatten the localized title
        $favorites->getCollection()->transform(function ($recipe) use ($locale) {
            $recipe->title = $recipe->title[$locale] ?? $recipe->title['en'] ?? $recipe->slug;

            return $recipe;
        });

        // 4. Return the response using the RecipeResource collection
        return response()->json([
            'status' => 'success',
            'data' => RecipeResource::collection($favorites->getCollection()),
            'meta' => [
                'current_page' => $favorites->currentPage(),
                'last_page' => $favorites->lastPage(),
                'total' => $favorites->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/favorites/{slug}/toggle',
        summary: 'Toggle a recipe in favorites',
        security: [['bearerAuth' => []]],
        tags: ['Favorites']
    )]
    #[OA\Response(response: 200, description: 'Favorite toggled successfully')]
    #[OA\Response(response: 404, description: 'Recipe not found')]
    public function toggle(string $slug, Request $request)
    {
        if (! Recipe::where('slug', $slug)->exists()) {
            return response()->json(['status' => 'error', 'message' => 'Recipe not found.'], 404);
        }

        // Laravel magic: Detach the record if it exists, attach it if it does not exist
        $request->user()->favoriteRecipes()->toggle($slug);

        return response()->json([
            'status' => 'success',
            'message' => 'Favorite status updated.',
        ]);
    }
}
