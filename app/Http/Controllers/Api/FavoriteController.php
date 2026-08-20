<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
    public function index(Request $request)
    {
        // Fetch the current user's favorite recipes, paginated for the PWA frontend
        $favorites = $request->user()->favoriteRecipes()->paginate(15);

        // Optional: Apply the same multi-language localization logic for the title
        // here as seen in the RecipeController, if required.

        return response()->json([
            'status' => 'success',
            'data' => $favorites->items(),
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
