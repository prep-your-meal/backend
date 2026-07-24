<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MealPlan;
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
    #[OA\Parameter(
        name: 'portions',
        in: 'query',
        required: false,
        description: 'Number of portions to calculate (default: 1)',
        schema: new OA\Schema(type: 'integer', default: 1)
    )]
    #[OA\Response(response: 200, description: 'Categorized shopping list')]
    public function index(Request $request)
    {
        try {
            $today = Carbon::today();
            $userId = $request->user()->id;

            $portions = (int) $request->query('portions', 1);

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

                foreach ($plan->recipe->ingredients as $ingredient) {
                    $slug = $ingredient->slug;
                    $amountForRecipe = $ingredient->pivot->amount * $portions;

                    if (! isset($ingredientsMap[$slug])) {
                        $ingredientsMap[$slug] = [
                            'name' => $ingredient->name,
                            'unit' => $ingredient->unit,
                            'category' => $ingredient->category ?? 'Uncategorized',
                            'total_amount' => 0,
                        ];
                    }

                    $ingredientsMap[$slug]['total_amount'] += $amountForRecipe;
                }
            }

            $categorizedList = [];

            foreach ($ingredientsMap as $item) {
                $item['total_amount'] = round($item['total_amount']);
                $category = $item['category'];

                if (! isset($categorizedList[$category])) {
                    $categorizedList[$category] = [];
                }

                $categorizedList[$category][] = $item;
            }

            return response()->json([
                'status' => 'success',
                'portions_calculated' => $portions,
                'data' => $categorizedList,
            ]);

        } catch (\Exception $e) {
            Log::error('Shopping List Aggregation Error: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => 'Failed to generate shopping list.'], 500);
        }
    }
}
