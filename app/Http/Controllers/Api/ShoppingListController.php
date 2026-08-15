<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\MealPlan;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class ShoppingListController extends Controller
{
    #[OA\Get(
        path: '/shopping-list',
        summary: 'Get the smart shopping list',
        security: [['bearerAuth' => []]],
        tags: ['Shopping']
    )]
    #[OA\Response(response: 200, description: 'Categorized and accurately scaled shopping list')]
    public function index(Request $request)
    {
        try {
            $today = Carbon::today();
            $userId = $request->user()->id;

            // Fetch current meal plan including recipes and their ingredients
            $currentPlan = MealPlan::with(['recipe.ingredients'])
                ->where('user_id', $userId)
                ->where('scheduled_for', '>=', $today)
                ->get();

            if ($currentPlan->isEmpty()) {
                return response()->json(['status' => 'success', 'data' => []]);
            }

            $ingredientsMap = [];

            foreach ($currentPlan as $plan) {
                if (! $plan->recipe) {
                    continue;
                }

                /** @var Recipe $recipe */
                $recipe = $plan->recipe;

                // The amount of portions planned for this specific meal on this day
                $plannedPortions = $plan->portions;

                // The amount of portions the original recipe was designed for (fallback to 1 to prevent division by zero)
                $defaultPortions = $recipe->default_portions > 0 ? $recipe->default_portions : 1;

                foreach ($recipe->ingredients as $ingredient) {
                    /** @var Ingredient $ingredient */
                    $slug = $ingredient->slug;

                    /** @phpstan-ignore-next-line */
                    $baseAmount = $ingredient->pivot->amount;

                    // CORE LOGIC: Scale the ingredient amount dynamically based on planned portions
                    $scaledAmount = ($baseAmount / $defaultPortions) * $plannedPortions;

                    if (! isset($ingredientsMap[$slug])) {
                        $ingredientsMap[$slug] = [
                            'name' => $ingredient->name,
                            'unit' => $ingredient->unit,
                            'category' => $ingredient->category ?? 'Uncategorized',
                            'total_amount' => 0,
                        ];
                    }

                    $ingredientsMap[$slug]['total_amount'] += $scaledAmount;
                }
            }

            $categorizedList = [];

            foreach ($ingredientsMap as $item) {
                // Round the final aggregated amount to 2 decimal places for cleaner output
                $item['total_amount'] = round($item['total_amount'], 2);
                $category = $item['category'];

                if (! isset($categorizedList[$category])) {
                    $categorizedList[$category] = [];
                }

                $categorizedList[$category][] = $item;
            }

            return response()->json([
                'status' => 'success',
                'data' => $categorizedList,
            ]);

        } catch (\Exception $e) {
            Log::error('Shopping List Aggregation Error: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => 'Failed to generate shopping list.'], 500);
        }
    }
}
