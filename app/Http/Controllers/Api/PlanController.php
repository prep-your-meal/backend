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
        summary: 'Get the meal plan for a specific date range',
        security: [['bearerAuth' => []]],
        tags: ['Meal Plan']
    )]
    #[OA\Parameter(name: 'start_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'end_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Meal plan data for the requested date range')]
    public function current(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
            ]);

            $user = $request->user();
            $userId = $user->id;

            $startDate = $request->query('start_date', Carbon::now()->startOfWeek()->format('Y-m-d'));
            $endDate = $request->query('end_date', Carbon::now()->endOfWeek()->format('Y-m-d'));

            // Premium Check: Restrict viewing plans outside the current week
            if (! $user->isPremium() && ! Carbon::parse($startDate)->isCurrentWeek()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Viewing meal plans for future or past weeks is only available for premium members.',
                    'requires_premium' => true,
                ], 403);
            }

            $currentPlan = MealPlan::with(['recipe.ingredients'])
                ->where('user_id', $userId)
                ->whereBetween('scheduled_for', [$startDate, $endDate])
                ->orderBy('scheduled_for', 'asc')
                ->get();

            if ($currentPlan->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No active meal plan found for this date range.',
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
                'message' => 'Failed to retrieve the meal plan.',
            ], 500);
        }
    }

    #[OA\Post(
        path: '/plan/generate',
        summary: 'Generate a smart meal plan based on preferences',
        description: 'Generates a meal plan minimizing food waste via overlapping ingredients. Caps meals to remaining days in the week.',
        security: [['bearerAuth' => []]],
        tags: ['Meal Plan']
    )]
    #[OA\RequestBody(
        required: false,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'start_date', type: 'string', format: 'date', example: '2026-09-21'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Generated meal plan')]
    #[OA\Response(response: 400, description: 'Not enough available recipes')]
    #[OA\Response(response: 500, description: 'Server error during generation')]
    public function generate(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'start_date' => 'nullable|date',
            ]);

            $user = $request->user();
            $userId = $user->id;
            $defaultPortions = $user->default_portions ?? 2;

            // Determine the start date (defaults to today)
            $startDate = $request->input('start_date')
                ? Carbon::parse($request->input('start_date'))
                : Carbon::today();

            // Premium Check: Restrict generating plans for other weeks
            if (! $user->isPremium() && ! $startDate->isCurrentWeek()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Generating meal plans for future or past weeks is only available for premium members.',
                    'requires_premium' => true,
                ], 403);
            }

            // Calculate how many days are left in the week for the given start date
            $endOfWeek = $startDate->copy()->endOfWeek();
            $availableDays = (int) $startDate->diffInDays($endOfWeek) + 1;

            // Cap the target meals to ensure they do not exceed the current week boundary
            $preferredTargetMeals = (int) ($user->target_meals_per_week ?? 7);
            $targetMeals = min($preferredTargetMeals, $availableDays);

            $availableRecipeSlugs = $this->buildPreferenceQuery($user)->pluck('slug');

            if ($availableRecipeSlugs->isEmpty()) {
                $availableRecipeSlugs = $this->buildAllergyFallbackQuery($user)->pluck('slug');
            }

            if ($availableRecipeSlugs->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Not enough available recipes matching your strict dietary requirements.',
                ], 400);
            }

            // Using firstOrFail() natively informs PHPStan that this will return a valid Model
            $seedRecipe = Recipe::with('ingredients')
                ->whereIn('slug', $availableRecipeSlugs)
                ->inRandomOrder()
                ->firstOrFail();

            $selectedSlugs = collect([$seedRecipe->slug]);
            $shouldMinimizeWaste = $user->minimize_food_waste ?? true;

            // Only attempt food waste optimization if we actually need more than 1 meal
            if ($shouldMinimizeWaste && $targetMeals > 1) {
                $seedIngredientSlugs = DB::table('ingredient_recipe')
                    ->join('ingredients', 'ingredient_recipe.ingredient_slug', '=', 'ingredients.slug')
                    ->where('ingredient_recipe.recipe_slug', $seedRecipe->slug)
                    ->where('ingredients.is_pantry_item', false)
                    ->pluck('ingredient_recipe.ingredient_slug');

                $overlappingSlugs = DB::table('ingredient_recipe')
                    ->select('ingredient_recipe.recipe_slug', DB::raw('COUNT(ingredient_recipe.ingredient_slug) as shared_count'))
                    ->whereIn('ingredient_recipe.ingredient_slug', $seedIngredientSlugs)
                    ->where('ingredient_recipe.recipe_slug', '!=', $seedRecipe->slug)
                    ->whereIn('ingredient_recipe.recipe_slug', $availableRecipeSlugs)
                    ->groupBy('ingredient_recipe.recipe_slug')
                    ->orderByDesc('shared_count')
                    ->limit($targetMeals - 1)
                    ->pluck('ingredient_recipe.recipe_slug');

                $selectedSlugs = $selectedSlugs->concat($overlappingSlugs);
            }

            // Pad with random recipes if the food-waste matching didn't yield enough results
            if ($selectedSlugs->count() < $targetMeals) {
                $needed = $targetMeals - $selectedSlugs->count();

                $paddingSlugs = Recipe::whereIn('slug', $availableRecipeSlugs)
                    ->whereNotIn('slug', $selectedSlugs)
                    ->inRandomOrder()
                    ->limit($needed)
                    ->pluck('slug');

                $selectedSlugs = $selectedSlugs->concat($paddingSlugs);
            }

            $selectedRecipes = Recipe::with('ingredients')
                ->whereIn('slug', $selectedSlugs)
                ->get()
                ->sortBy(function ($recipe) use ($selectedSlugs) {
                    return $selectedSlugs->search($recipe->slug);
                })->values();

            DB::beginTransaction();

            // Only delete the exact days we are going to override in the current week scope
            $endDate = $startDate->copy()->addDays($targetMeals - 1);
            MealPlan::where('user_id', $userId)
                ->whereBetween('scheduled_for', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                ->delete();

            $planResponse = [];

            foreach ($selectedRecipes as $index => $recipe) {
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
    #[OA\Parameter(
        name: 'date',
        in: 'path',
        required: true,
        description: 'The target date in YYYY-MM-DD format',
        schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-17')
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
    #[OA\Response(response: 200, description: 'Recipe manually added or swapped')]
    public function addManual(Request $request, string $date): JsonResponse
    {
        $request->validate([
            'recipe_slug' => ['required', 'string', 'exists:recipes,slug'],
        ]);

        $user = $request->user();

        // Premium Check
        if (! $user->isPremium() && ! Carbon::parse($date)->isCurrentWeek()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Modifying meal plans for future or past weeks is only available for premium members.',
                'requires_premium' => true,
            ], 403);
        }

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
    #[OA\Parameter(
        name: 'date',
        in: 'path',
        required: true,
        description: 'The target date in YYYY-MM-DD format',
        schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-17')
    )]
    #[OA\Response(response: 200, description: 'Meal removed from plan')]
    public function clearDate(Request $request, string $date): JsonResponse
    {
        $user = $request->user();

        // Premium Check
        if (! $user->isPremium() && ! Carbon::parse($date)->isCurrentWeek()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Modifying meal plans for future or past weeks is only available for premium members.',
                'requires_premium' => true,
            ], 403);
        }

        MealPlan::where('user_id', $user->id)
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
        description: 'Returns candidate recipes respecting dietary preferences, allergy blacklists, and prioritizing food-waste reduction via overlapping ingredients with current active meals.',
        security: [['bearerAuth' => []]],
        tags: ['Meal Plan']
    )]
    #[OA\Parameter(
        name: 'date',
        in: 'path',
        required: true,
        description: 'The target date in YYYY-MM-DD format',
        schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-17')
    )]
    #[OA\Response(response: 200, description: 'List of alternative recipes')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function alternatives(Request $request, string $date): JsonResponse
    {
        try {
            $user = $request->user();

            // Premium Check
            if (! $user->isPremium() && ! Carbon::parse($date)->isCurrentWeek()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Searching alternatives for future or past weeks is only available for premium members.',
                    'requires_premium' => true,
                ], 403);
            }

            $currentMealForDate = MealPlan::where('user_id', $user->id)
                ->where('scheduled_for', $date)
                ->value('recipe_slug');

            $query = $this->buildPreferenceQuery($user);
            if ($currentMealForDate) {
                $query->where('slug', '!=', $currentMealForDate);
            }

            $availableRecipeSlugs = $query->pluck('slug');

            if ($availableRecipeSlugs->isEmpty()) {
                $fallbackQuery = $this->buildAllergyFallbackQuery($user);
                if ($currentMealForDate) {
                    $fallbackQuery->where('slug', '!=', $currentMealForDate);
                }
                $availableRecipeSlugs = $fallbackQuery->pluck('slug');
            }

            if ($availableRecipeSlugs->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'data' => [],
                ]);
            }

            $shouldMinimizeWaste = $user->minimize_food_waste ?? true;
            $rankedSlugs = collect();

            if ($shouldMinimizeWaste) {
                // LOGIC FIX: Determine the start and end of the target date's week
                $startOfWeek = Carbon::parse($date)->startOfWeek()->format('Y-m-d');
                $endOfWeek = Carbon::parse($date)->endOfWeek()->format('Y-m-d');

                // Look at all meals WITHIN THE SAME WEEK to find overlapping ingredients,
                // allowing users to use up leftovers from earlier in the week.
                $activePlanSlugs = MealPlan::where('user_id', $user->id)
                    ->whereBetween('scheduled_for', [$startOfWeek, $endOfWeek])
                    ->where('scheduled_for', '!=', $date)
                    ->pluck('recipe_slug');

                if ($activePlanSlugs->isNotEmpty()) {
                    $activeIngredientSlugs = DB::table('ingredient_recipe')
                        ->join('ingredients', 'ingredient_recipe.ingredient_slug', '=', 'ingredients.slug')
                        ->whereIn('ingredient_recipe.recipe_slug', $activePlanSlugs)
                        ->where('ingredients.is_pantry_item', false)
                        ->pluck('ingredient_recipe.ingredient_slug')
                        ->unique();

                    if ($activeIngredientSlugs->isNotEmpty()) {
                        $rankedSlugs = DB::table('ingredient_recipe')
                            ->select('ingredient_recipe.recipe_slug', DB::raw('COUNT(ingredient_recipe.ingredient_slug) as shared_count'))
                            ->whereIn('ingredient_recipe.ingredient_slug', $activeIngredientSlugs)
                            ->whereIn('ingredient_recipe.recipe_slug', $availableRecipeSlugs)
                            ->groupBy('ingredient_recipe.recipe_slug')
                            ->orderByDesc('shared_count')
                            ->pluck('ingredient_recipe.recipe_slug');
                    }
                }
            }

            $neededRandom = 15 - $rankedSlugs->count();
            $randomSlugs = collect();
            if ($neededRandom > 0) {
                $randomSlugs = collect($availableRecipeSlugs)
                    ->reject(fn ($slug) => $rankedSlugs->contains($slug))
                    ->shuffle()
                    ->take($neededRandom);
            }

            $finalSlugs = $rankedSlugs->concat($randomSlugs)->take(15);

            $finalRecommendations = Recipe::with('ingredients')
                ->whereIn('slug', $finalSlugs)
                ->get()
                ->sortBy(function ($recipe) use ($finalSlugs) {
                    return $finalSlugs->search($recipe->slug);
                })->values();

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

        $query = Recipe::query()
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
        $query = Recipe::query();

        $allergies = array_filter((array) $user->allergies);
        if (! empty($allergies)) {
            foreach ($allergies as $allergy) {
                $query->whereJsonDoesntContain('categories', $allergy);
            }
        }

        return $query;
    }
}
