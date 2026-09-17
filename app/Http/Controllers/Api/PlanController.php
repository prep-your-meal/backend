<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecipeResource;
use App\Models\MealPlan;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

            $formattedPlan = $currentPlan->map(function ($plan) {
                $planData = $plan->toArray();

                if ($plan->recipe) {
                    $planData['recipe'] = new RecipeResource($plan->recipe);
                }

                return $planData;
            });

            return response()->json([
                'status' => 'success',
                'data' => $formattedPlan,
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
        description: 'Generates a meal plan minimizing food waste via overlapping ingredients.',
        security: [['bearerAuth' => []]],
        tags: ['Meal Plan']
    )]
    public function generate(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $userId = $user->id;

            $targetMeals = $user->target_meals_per_week ?? 7;
            $defaultPortions = $user->default_portions ?? 2;

            $query = $this->buildPreferenceQuery($user);
            $availableRecipes = $query->get();

            if ($availableRecipes->isEmpty()) {
                $availableRecipes = $this->buildAllergyFallbackQuery($user)->get();
            }

            if ($availableRecipes->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Not enough available recipes matching your strict dietary requirements.',
                ], 400);
            }

            // Tell PHPStan exactly what $seedRecipe is
            $seedRecipe = $availableRecipes->random();
            assert($seedRecipe instanceof Recipe);

            $selectedRecipes = collect([$seedRecipe]);
            $shouldMinimizeWaste = $user->minimize_food_waste ?? true;

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

            if ($selectedRecipes->count() < $targetMeals) {
                $needed = $targetMeals - $selectedRecipes->count();
                $paddingRecipes = $availableRecipes
                    ->whereNotIn('slug', $selectedRecipes->pluck('slug'))
                    ->random(min($needed, $availableRecipes->count() - $selectedRecipes->count()));

                $selectedRecipes = $selectedRecipes->merge($paddingRecipes);
            }

            DB::beginTransaction();
            MealPlan::where('user_id', $userId)->where('scheduled_for', '>=', Carbon::today())->delete();

            $startDate = Carbon::today();
            $planResponse = [];

            foreach ($selectedRecipes as $index => $recipe) {
                // Explicit assertion for PHPStan inside the loop
                assert($recipe instanceof Recipe);

                $scheduledDate = $startDate->copy()->addDays($index);

                MealPlan::create([
                    'user_id' => $userId,
                    'recipe_slug' => $recipe->slug,
                    'scheduled_for' => $scheduledDate->format('Y-m-d'),
                    'portions' => $defaultPortions,
                ]);

                $planResponse[] = [
                    'date' => $scheduledDate->format('Y-m-d'),
                    'recipe' => new RecipeResource($recipe),
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

    #[OA\Post(
        path: '/plan/{date}/add',
        summary: 'Manually add or swap a specific recipe on a date',
        security: [['bearerAuth' => []]],
        tags: ['Meal Plan']
    )]
    public function addManual(Request $request, string $date): JsonResponse
    {
        $request->validate([
            'recipe_slug' => ['required', 'string', 'exists:recipes,slug'],
        ]);

        $user = $request->user();
        $defaultPortions = $user->default_portions ?? 2;

        $mealPlan = MealPlan::updateOrCreate(
            ['user_id' => $user->id, 'scheduled_for' => $date],
            ['recipe_slug' => $request->recipe_slug, 'portions' => $defaultPortions]
        );

        $mealPlan->load('recipe.ingredients');
        $responseData = $mealPlan->toArray();

        if ($mealPlan->recipe) {
            $responseData['recipe'] = new RecipeResource($mealPlan->recipe);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Meal successfully scheduled.',
            'data' => $responseData,
        ]);
    }

    #[OA\Delete(
        path: '/plan/{date}',
        summary: 'Clear the meal scheduled for a specific date',
        security: [['bearerAuth' => []]],
        tags: ['Meal Plan']
    )]
    public function clearDate(Request $request, string $date): JsonResponse
    {
        MealPlan::where('user_id', $request->user()->id)
            ->where('scheduled_for', $date)
            ->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Meal removed from the plan for this date.',
        ]);
    }

    #[OA\Get(
        path: '/plan/{date}/alternatives',
        summary: 'Get smart recipe alternatives for a specific date',
        description: 'Returns candidate recipes respecting dietary preferences, allergy blacklists, and prioritizing food-waste reduction.',
        security: [['bearerAuth' => []]],
        tags: ['Meal Plan']
    )]
    public function alternatives(Request $request, string $date): JsonResponse
    {
        try {
            $user = $request->user();

            $currentMealForDate = MealPlan::where('user_id', $user->id)
                ->where('scheduled_for', $date)
                ->value('recipe_slug');

            $query = $this->buildPreferenceQuery($user);
            if ($currentMealForDate) {
                $query->where('slug', '!=', $currentMealForDate);
            }

            $availableRecipes = $query->get();

            if ($availableRecipes->isEmpty()) {
                $fallbackQuery = $this->buildAllergyFallbackQuery($user);
                if ($currentMealForDate) {
                    $fallbackQuery->where('slug', '!=', $currentMealForDate);
                }
                $availableRecipes = $fallbackQuery->get();
            }

            if ($availableRecipes->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'data' => [],
                ]);
            }

            $shouldMinimizeWaste = $user->minimize_food_waste ?? true;
            $rankedRecipes = collect();

            if ($shouldMinimizeWaste) {
                $activePlanSlugs = MealPlan::where('user_id', $user->id)
                    ->where('scheduled_for', '>=', Carbon::today())
                    ->where('scheduled_for', '!=', $date)
                    ->pluck('recipe_slug');

                if ($activePlanSlugs->isNotEmpty()) {
                    $activeIngredientSlugs = DB::table('ingredient_recipe')
                        ->whereIn('recipe_slug', $activePlanSlugs)
                        ->pluck('ingredient_slug')
                        ->unique();

                    if ($activeIngredientSlugs->isNotEmpty()) {
                        $rankedSlugs = DB::table('ingredient_recipe')
                            ->select('recipe_slug', DB::raw('COUNT(ingredient_slug) as shared_count'))
                            ->whereIn('ingredient_slug', $activeIngredientSlugs)
                            ->whereIn('recipe_slug', $availableRecipes->pluck('slug'))
                            ->groupBy('recipe_slug')
                            ->orderByDesc('shared_count')
                            ->pluck('recipe_slug');

                        $rankedMap = array_flip($rankedSlugs->toArray());

                        $wasteMinimizingRecipes = $availableRecipes
                            ->whereIn('slug', $rankedSlugs)
                            ->sortBy(function ($recipe) use ($rankedMap) {
                                assert($recipe instanceof Recipe);

                                return $rankedMap[$recipe->slug] ?? 9999;
                            });

                        $remainingRecipes = $availableRecipes
                            ->whereNotIn('slug', $rankedSlugs)
                            ->shuffle();

                        $rankedRecipes = $wasteMinimizingRecipes->concat($remainingRecipes);
                    }
                }
            }

            if ($rankedRecipes->isEmpty()) {
                $rankedRecipes = $availableRecipes->shuffle();
            }

            $finalRecommendations = $rankedRecipes->take(15)->values();

            return response()->json([
                'status' => 'success',
                'data' => RecipeResource::collection($finalRecommendations),
            ]);
        } catch (\Exception $e) {
            Log::error('Meal Plan Alternatives Error: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve alternative recipes.',
            ], 500);
        }
    }

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
