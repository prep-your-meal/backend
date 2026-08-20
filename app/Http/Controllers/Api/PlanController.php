<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MealPlan;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class PlanController extends Controller
{
    #[OA\Get(
        path: '/plan',
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

            $currentPlan = Cache::remember("meal_plan_user_{$userId}", now()->endOfWeek(), function () use ($userId, $today) {
                return MealPlan::with(['recipe.ingredients'])
                    ->where('user_id', $userId)
                    ->where('scheduled_for', '>=', $today)
                    ->orderBy('scheduled_for', 'asc')
                    ->get();
            });

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
        path: '/plan/generate',
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

            $targetMeals = $user->target_meals_per_week ?? 7;
            $defaultPortions = $user->default_portions ?? 2;

            // 1. Build query using our centralized preference logic
            $query = $this->buildPreferenceQuery($user);
            $availableRecipes = $query->get();

            // Fallback: Drop nice-to-have preferences, but KEEP strict allergy blacklist
            if ($availableRecipes->isEmpty()) {
                $availableRecipes = $this->buildAllergyFallbackQuery($user)->get();
            }

            if ($availableRecipes->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Not enough available recipes matching your strict dietary requirements.',
                ], 400);
            }

            // 3. Pick a random "Seed" recipe
            /** @var Recipe $seedRecipe */
            $seedRecipe = $availableRecipes->random();
            $selectedRecipes = collect([$seedRecipe]);
            $shouldMinimizeWaste = $user->minimize_food_waste ?? true;

            // 4. Find overlapping ingredients to minimize food waste
            if ($shouldMinimizeWaste && $targetMeals > 1) {
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

            // 5. Pad with random recipes if overlaps didn't yield enough meals
            if ($selectedRecipes->count() < $targetMeals) {
                $needed = $targetMeals - $selectedRecipes->count();
                $paddingRecipes = $availableRecipes
                    ->whereNotIn('slug', $selectedRecipes->pluck('slug'))
                    ->random(min($needed, $availableRecipes->count() - $selectedRecipes->count()));

                $selectedRecipes = $selectedRecipes->merge($paddingRecipes);
            }

            // 6. Save the generated plan
            DB::beginTransaction();
            MealPlan::where('user_id', $userId)->where('scheduled_for', '>=', Carbon::today())->delete();

            $startDate = Carbon::today();
            $planResponse = [];

            foreach ($selectedRecipes as $index => $recipe) {
                /** @var Recipe $recipe */
                $scheduledDate = $startDate->copy()->addDays($index);

                MealPlan::create([
                    'user_id' => $userId,
                    'recipe_slug' => $recipe->slug,
                    'scheduled_for' => $scheduledDate->format('Y-m-d'),
                    'portions' => $defaultPortions,
                ]);

                $planResponse[] = [
                    'date' => $scheduledDate->format('Y-m-d'),
                    'recipe' => $recipe,
                ];
            }

            DB::commit();
            Cache::forget("meal_plan_user_{$userId}");

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

    #[OA\Put(
        path: '/plan/{date}/swap',
        summary: 'Swap a specific meal in the plan',
        security: [['bearerAuth' => []]],
        tags: ['Meal Plan']
    )]
    #[OA\Response(response: 200, description: 'Meal successfully swapped')]
    #[OA\Response(response: 400, description: 'No alternative recipes available')]
    #[OA\Response(response: 404, description: 'No meal scheduled for this date')]
    public function swap(Request $request, string $date): JsonResponse
    {
        $user = $request->user();

        $mealPlan = MealPlan::where('user_id', $user->id)
            ->where('scheduled_for', $date)
            ->first();

        if (! $mealPlan) {
            return response()->json([
                'status' => 'error',
                'message' => 'No meal scheduled for this date.',
            ], 404);
        }

        $query = $this->buildPreferenceQuery($user);
        $query->where('slug', '!=', $mealPlan->recipe_slug);

        $availableRecipes = $query->get();

        if ($availableRecipes->isEmpty()) {
            $fallbackQuery = $this->buildAllergyFallbackQuery($user);
            $fallbackQuery->where('slug', '!=', $mealPlan->recipe_slug);
            $availableRecipes = $fallbackQuery->get();
        }

        if ($availableRecipes->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No alternative recipes available.',
            ], 400);
        }

        /** @var Recipe $newRecipe */
        $newRecipe = $availableRecipes->random();

        $mealPlan->update(['recipe_slug' => $newRecipe->slug]);
        Cache::forget("meal_plan_user_{$user->id}");

        return response()->json([
            'status' => 'success',
            'message' => 'Meal successfully swapped.',
            'data' => $mealPlan->load('recipe.ingredients'),
        ]);
    }

    #[OA\Post(
        path: '/plan/{date}/add',
        summary: 'Manually add a specific recipe to a date',
        security: [['bearerAuth' => []]],
        tags: ['Meal Plan']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['recipe_slug'],
            properties: [
                new OA\Property(property: 'recipe_slug', type: 'string', example: 'chicken-curry'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Recipe manually added to plan')]
    public function addManual(Request $request, string $date): JsonResponse
    {
        $request->validate([
            'recipe_slug' => ['required', 'string', 'exists:recipes,slug'],
        ]);

        $user = $request->user();
        $defaultPortions = $user->default_portions ?? 2;

        // Use updateOrCreate so if a meal already exists for this date, it gets overwritten
        $mealPlan = MealPlan::updateOrCreate(
            ['user_id' => $user->id, 'scheduled_for' => $date],
            ['recipe_slug' => $request->recipe_slug, 'portions' => $defaultPortions]
        );

        Cache::forget("meal_plan_user_{$user->id}");

        return response()->json([
            'status' => 'success',
            'message' => 'Meal successfully scheduled.',
            'data' => $mealPlan->load('recipe.ingredients'),
        ]);
    }

    #[OA\Delete(
        path: '/plan/{date}',
        summary: 'Clear the meal scheduled for a specific date',
        security: [['bearerAuth' => []]],
        tags: ['Meal Plan']
    )]
    #[OA\Response(response: 200, description: 'Meal removed from plan')]
    public function clearDate(Request $request, string $date): JsonResponse
    {
        $user = $request->user();

        $deleted = MealPlan::where('user_id', $user->id)
            ->where('scheduled_for', $date)
            ->delete();

        if ($deleted) {
            Cache::forget("meal_plan_user_{$user->id}");
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Meal removed from the plan for this date.',
        ]);
    }

    /**
     * Reusable query builder for strict user preferences and 30-day rule.
     */
    private function buildPreferenceQuery($user): Builder
    {
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        $query = Recipe::with('ingredients')
            ->whereNotIn('slug', function ($subQuery) use ($thirtyDaysAgo, $user) {
                $subQuery->select('recipe_slug')
                    ->from('meal_plans')
                    ->where('user_id', $user->id)
                    ->where('scheduled_for', '>=', $thirtyDaysAgo);
            });

        $dietPrefs = array_filter((array) $user->dietary_preferences);
        if (! empty($dietPrefs)) {
            $query->where(function (Builder $q) use ($dietPrefs) {
                foreach ($dietPrefs as $pref) {
                    $q->orWhereJsonContains('categories', $pref);
                }
            });
        }

        $fitnessPrefs = array_filter((array) $user->fitness_goals);
        if (! empty($fitnessPrefs)) {
            $query->where(function (Builder $q) use ($fitnessPrefs) {
                foreach ($fitnessPrefs as $pref) {
                    $q->orWhereJsonContains('categories', $pref);
                }
            });
        }

        $logisticsPrefs = array_filter((array) $user->logistics_preferences);
        if (! empty($logisticsPrefs)) {
            $query->where(function (Builder $q) use ($logisticsPrefs) {
                foreach ($logisticsPrefs as $pref) {
                    $q->orWhereJsonContains('categories', $pref);
                }
            });
        }

        $allergies = array_filter((array) $user->allergies);
        if (! empty($allergies)) {
            foreach ($allergies as $allergy) {
                $query->whereJsonDoesntContain('categories', $allergy);
            }
        }

        return $query;
    }

    /**
     * Reusable fallback query that ONLY respects the allergy blacklist.
     */
    private function buildAllergyFallbackQuery($user): Builder
    {
        $query = Recipe::with('ingredients');

        $allergies = array_filter((array) $user->allergies);
        if (! empty($allergies)) {
            foreach ($allergies as $allergy) {
                $query->whereJsonDoesntContain('categories', $allergy);
            }
        }

        return $query;
    }
}
