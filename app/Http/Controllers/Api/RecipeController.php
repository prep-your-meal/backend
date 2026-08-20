<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class RecipeController extends Controller
{
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
        // firstOrFail() throws a 404 automatically if the slug doesn't exist
        $recipe = Recipe::with('ingredients')->where('slug', $slug)->firstOrFail();

        return response()->json([
            'status' => 'success',
            'data' => $recipe,
        ]);
    }
}
