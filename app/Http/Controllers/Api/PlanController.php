<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MealPlan;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class PlanController extends Controller
{
    #[OA\Get(
        path: '/api/plan',
        summary: 'Get the currently active meal plan',
        security: [['bearerAuth' => []]],
        tags: ['Meal Plan']
    )]
    #[OA\Response(response: 200, description: 'Current meal plan data')]
    public function current(Request $request): JsonResponse
    {
        try {
            $today = Carbon::today();
            $userId = $request->user()->id;

            $currentPlan = MealPlan::with(['recipe.ingredients'])
                ->where('user_id', $userId)
                ->where('scheduled_for', '>=', $today)
                ->orderBy('scheduled_for', 'asc')
                ->get();

            if ($currentPlan->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No active meal plan found.',
                    'data' => [],
                ]);
            }

            return response()->json([
                'status' => 'success',
                'data' => $currentPlan,
            ]);

        } catch (\Exception $e) {
            Log::error('Meal Plan Retrieval Error: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve the current meal plan.',
            ], 500);
        }
    }

    #[OA\Post(
        path: '/api/plan/generate',
        summary: 'Generate a smart meal plan based on preferences',
        description: 'Generates a meal plan minimizing food waste via overlapping ingredients, respects user preferences, and avoids repeating meals from the last 30 days.',
        security: [['bearerAuth' => []]],
        tags: ['Meal Plan']
    )]
    #[OA\Response(response: 200, description: 'Generated meal plan')]
    #[OA\Response(response: 400, description: 'Not enough available recipes')]
    #[OA\Response(response: 500, description: 'Server error during generation')]
    public function generate(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $userId = $user->id;

            // Retrieve target meals from user preferences, fallback to 7 if not set
            $targetMeals = $user->target_meals_per_week ?? 7;
            $thirtyDaysAgo = Carbon::now()->subDays(30);

            // 1. Build query applying preferences and the 30-day rule
            $query = Recipe::with('ingredients')
                ->whereNotIn('slug', function ($subQuery) use ($thirtyDaysAgo, $userId) {
                    $subQuery->select('recipe_slug')
                        ->from('meal_plans')
                        ->where('user_id', $userId)
                        ->where('scheduled_for', '>=', $thirtyDaysAgo);
                });

            // Casting to (array) satisfies Larastan, handles null values gracefully ([]),
            // and respects Laravel's runtime casts without needing redundant type checks.
            foreach ((array) $user->dietary_preferences as $pref) {
                $query->whereJsonContains('categories', $pref);
            }

            foreach ((array) $user->fitness_goals as $pref) {
                $query->whereJsonContains('categories', $pref);
            }

            foreach ((array) $user->logistics_preferences as $pref) {
                $query->whereJsonContains('categories', $pref);
            }

            $availableRecipes = $query->get();

            // Fallback: If strict preferences + 30-day rule yields nothing, drop the 30-day rule
            if ($availableRecipes->isEmpty()) {
                $availableRecipes = Recipe::with('ingredients')->get();
            }

            if ($availableRecipes->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Not enough available recipes in the database.',
                ], 400);
            }

            // 2. Pick a random "Seed" recipe
            $seedRecipe = $availableRecipes->random();
            $selectedRecipes = collect([$seedRecipe]);

            // 3. Find overlapping ingredients to minimize food waste
            if ($targetMeals > 1) {
                $seedIngredientSlugs = DB::table('ingredient_recipe')
                    ->where('recipe_slug', $seedRecipe->slug)
                    ->pluck('ingredient_slug');

                $overlappingSlugs = DB::table('ingredient_recipe')
                    ->select('recipe_slug', DB::raw('COUNT(ingredient_slug) as shared_count'))
                    ->whereIn('ingredient_slug', $seedIngredientSlugs)
                    ->where('recipe_slug', '!=', $seedRecipe->slug)
                    ->whereIn('recipe_slug', $availableRecipes->pluck('slug'))
                    ->groupBy('recipe_slug')
                    ->orderByDesc('shared_count')
                    ->limit($targetMeals - 1)
                    ->pluck('recipe_slug');

                if ($overlappingSlugs->isNotEmpty()) {
                    $overlappingRecipes = Recipe::with('ingredients')->whereIn('slug', $overlappingSlugs)->get();
                    $selectedRecipes = $selectedRecipes->merge($overlappingRecipes);
                }
            }

            // 4. Pad with random recipes if overlaps didn't yield enough meals
            if ($selectedRecipes->count() < $targetMeals) {
                $needed = $targetMeals - $selectedRecipes->count();
                $paddingRecipes = $availableRecipes
                    ->whereNotIn('slug', $selectedRecipes->pluck('slug'))
                    ->random(min($needed, $availableRecipes->count() - $selectedRecipes->count()));

                $selectedRecipes = $selectedRecipes->merge($paddingRecipes);
            }

            // 5. Save the generated plan to the database using a transaction
            DB::beginTransaction();

            // Clear any upcoming generated meals
            MealPlan::where('user_id', $userId)
                ->where('scheduled_for', '>=', Carbon::today())
                ->delete();

            $startDate = Carbon::today();
            $planResponse = [];

            foreach ($selectedRecipes as $index => $recipe) {
                $scheduledDate = $startDate->copy()->addDays($index);

                // Portions automatically set to cover the household
                $portions = 3;

                MealPlan::create([
                    'user_id' => $userId,
                    'recipe_slug' => $recipe->slug,
                    'scheduled_for' => $scheduledDate->format('Y-m-d'),
                    'portions' => $portions,
                ]);

                $planResponse[] = [
                    'date' => $scheduledDate->format('Y-m-d'),
                    'recipe' => $recipe,
                ];
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "{$targetMeals}-day smart meal plan successfully generated.",
                'data' => $planResponse,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Meal Plan Generation Error: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate meal plan.',
            ], 500);
        }
    }
}
