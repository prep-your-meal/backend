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

            // 1. Always fetch custom items first to ensure they are never lost
            $customItems = $request->user()->customShoppingItems()
                ->orderBy('created_at', 'desc')
                ->get();

            // 2. Fetch the current meal plan
            $currentPlan = MealPlan::with(['recipe.ingredients'])
                ->where('user_id', $userId)
                ->where('scheduled_for', '>=', $today)
                ->get();

            // 3. Early return if the meal plan is empty (keeping custom items intact)
            if ($currentPlan->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'recipes' => [],
                        'custom_items' => $customItems,
                    ],
                ]);
            }

            $ingredientsMap = [];

            foreach ($currentPlan as $plan) {
                if (! $plan->recipe) {
                    continue;
                }

                /** @var Recipe $recipe */
                $recipe = $plan->recipe;
                $plannedPortions = $plan->portions;
                $defaultPortions = $recipe->default_portions > 0 ? $recipe->default_portions : 1;

                foreach ($recipe->ingredients as $ingredient) {
                    /** @var Ingredient $ingredient */
                    $slug = $ingredient->slug;

                    /** @phpstan-ignore-next-line */
                    $baseAmount = $ingredient->pivot->amount;
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
                $item['total_amount'] = round($item['total_amount'], 2);
                $category = $item['category'];

                if (! isset($categorizedList[$category])) {
                    $categorizedList[$category] = [];
                }

                $categorizedList[$category][] = $item;
            }

            // 4. Return the correctly populated variable ($categorizedList)
            return response()->json([
                'status' => 'success',
                'data' => [
                    'recipes' => $categorizedList,
                    'custom_items' => $customItems,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Shopping List Aggregation Error: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => 'Failed to generate shopping list.'], 500);
        }
    }
}
