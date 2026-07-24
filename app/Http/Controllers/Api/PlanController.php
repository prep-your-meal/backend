<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MealPlan;
use App\Models\Recipe;
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
    public function current(Request $request)
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
        summary: 'Generate a new 7-day meal plan',
        security: [['bearerAuth' => []]],
        tags: ['Meal Plan']
    )]
    #[OA\Response(response: 200, description: 'Generated meal plan')]
    #[OA\Response(response: 400, description: 'Not enough unused recipes available')]
    public function generate(Request $request)
    {
        try {
            $thirtyDaysAgo = Carbon::now()->subDays(30);
            $userId = $request->user()->id;

            $availableRecipes = Recipe::with('ingredients')
                ->whereNotIn('slug', function ($query) use ($thirtyDaysAgo, $userId) {
                    $query->select('recipe_slug')
                        ->from('meal_plans')
                        ->where('user_id', $userId)
                        ->where('scheduled_for', '>=', $thirtyDaysAgo);
                })
                ->inRandomOrder()
                ->limit(7)
                ->get();

            if ($availableRecipes->count() < 7) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Not enough available recipes to fulfill the 30-day rule.',
                ], 400);
            }

            DB::beginTransaction();

            $startDate = Carbon::today();
            $planResponse = [];

            foreach ($availableRecipes as $index => $recipe) {
                $scheduledDate = $startDate->copy()->addDays($index);

                MealPlan::create([
                    'user_id' => $userId,
                    'recipe_slug' => $recipe->slug,
                    'scheduled_for' => $scheduledDate->format('Y-m-d'),
                ]);

                $planResponse[] = [
                    'date' => $scheduledDate->format('Y-m-d'),
                    'recipe' => $recipe,
                ];
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => '7-day meal plan successfully generated.',
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
