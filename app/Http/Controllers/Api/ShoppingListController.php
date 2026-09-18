<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\MealPlan;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class ShoppingListController extends Controller
{
    #[OA\Get(
        path: '/shopping-list',
        summary: 'Get the smart shopping list for a date range',
        security: [['bearerAuth' => []]],
        tags: ['Shopping']
    )]
    #[OA\Parameter(name: 'start_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'end_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(response: 200, description: 'Categorized and accurately scaled shopping list')]
    public function index(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
            ]);

            $user = $request->user();
            $userId = $user->id;

            // Default to current week (Monday to Sunday)
            $startDate = $request->query('start_date', Carbon::now()->startOfWeek()->format('Y-m-d'));
            $endDate = $request->query('end_date', Carbon::now()->endOfWeek()->format('Y-m-d'));

            // Premium Check: Restrict viewing shopping lists outside the current week
            if (! $user->isPremium() && ! Carbon::parse($startDate)->isCurrentWeek()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Viewing shopping lists for future or past weeks is only available for premium members.',
                    'requires_premium' => true,
                ], 403);
            }

            // 1. Fetch custom items SPECIFICALLY for the requested week
            $customItems = $user->customShoppingItems()
                ->where('week_start', $startDate)
                ->orderBy('created_at', 'desc')
                ->get();

            // 2. Fetch the meal plan for the specified date range
            $currentPlan = MealPlan::with(['recipe.ingredients'])
                ->where('user_id', $userId)
                ->whereBetween('scheduled_for', [$startDate, $endDate])
                ->get();

            // 3. Early return with explicit object casting (object)[] to prevent Vue frontend errors
            if ($currentPlan->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'recipes' => (object) [],
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

                    // Scale ingredient amount based on user-defined portions vs recipe default
                    $scaledAmount = ($baseAmount / $defaultPortions) * $plannedPortions;

                    if (! isset($ingredientsMap[$slug])) {
                        $ingredientsMap[$slug] = [
                            'slug' => $slug,
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

            // Group ingredients by their respective categories
            foreach ($ingredientsMap as $item) {
                $item['total_amount'] = round($item['total_amount'], 2);
                $category = $item['category'];

                if (! isset($categorizedList[$category])) {
                    $categorizedList[$category] = [];
                }

                $categorizedList[$category][] = $item;
            }

            // 4. Return the correctly populated variables with explicit object casting
            return response()->json([
                'status' => 'success',
                'data' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'recipes' => empty($categorizedList) ? (object) [] : (object) $categorizedList,
                    'custom_items' => $customItems,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Shopping List Aggregation Error: '.$e->getMessage());

            return response()->json(['status' => 'error', 'message' => 'Failed to generate shopping list.'], 500);
        }
    }
}
