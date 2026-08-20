<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class UserPreferenceController extends Controller
{
    #[OA\Get(
        path: '/user/preferences',
        summary: 'Retrieve user preferences',
        description: "Returns the currently authenticated user's meal plan preferences.",
        security: [['bearerAuth' => []]],
        tags: ['User']
    )]
    #[OA\Response(
        response: 200,
        description: 'Successful operation',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'success'),
                new OA\Property(
                    property: 'data',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'target_meals_per_week', type: 'integer', example: 3),
                        new OA\Property(property: 'default_portions', type: 'integer', example: 2),
                        new OA\Property(
                            property: 'dietary_preferences',
                            type: 'array',
                            items: new OA\Items(type: 'string', example: 'vegan')
                        ),
                        new OA\Property(
                            property: 'fitness_goals',
                            type: 'array',
                            items: new OA\Items(type: 'string', example: 'high-protein')
                        ),
                        new OA\Property(
                            property: 'logistics_preferences',
                            type: 'array',
                            items: new OA\Items(type: 'string', example: 'meal-prep-friendly')
                        ),
                        new OA\Property(
                            property: 'allergies',
                            type: 'array',
                            items: new OA\Items(type: 'string', example: 'nuts')
                        ),
                        new OA\Property(property: 'minimize_food_waste', type: 'boolean'),
                    ]
                ),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'status' => 'success',
            'data' => [
                'target_meals_per_week' => $user->target_meals_per_week,
                'default_portions' => $user->default_portions ?? 2, // Default value for new users
                'dietary_preferences' => $user->dietary_preferences ?? [],
                'fitness_goals' => $user->fitness_goals ?? [],
                'logistics_preferences' => $user->logistics_preferences ?? [],
                'allergies' => $user->allergies ?? [],
                'minimize_food_waste' => (bool) ($user->minimize_food_waste ?? true),
            ],
        ]);
    }

    #[OA\Put(
        path: '/user/preferences',
        summary: 'Update user preferences',
        description: 'Update the meal plan preferences for the authenticated user via the frontend wizard.',
        security: [['bearerAuth' => []]],
        tags: ['User']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['target_meals_per_week', 'default_portions'],
            properties: [
                new OA\Property(property: 'target_meals_per_week', type: 'integer', example: 4),
                new OA\Property(property: 'default_portions', type: 'integer', example: 2),
                new OA\Property(
                    property: 'dietary_preferences',
                    type: 'array',
                    items: new OA\Items(type: 'string', example: 'vegan')
                ),
                new OA\Property(
                    property: 'fitness_goals',
                    type: 'array',
                    items: new OA\Items(type: 'string', example: 'high-protein')
                ),
                new OA\Property(
                    property: 'logistics_preferences',
                    type: 'array',
                    items: new OA\Items(type: 'string', example: 'meal-prep-friendly')
                ),
                new OA\Property(
                    property: 'allergies',
                    type: 'array',
                    items: new OA\Items(type: 'string', example: 'nuts')
                ),
                new OA\Property(property: 'minimize_food_waste', type: 'boolean'),
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Preferences updated successfully')]
    #[OA\Response(response: 422, description: 'Validation Error')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function update(Request $request): JsonResponse
    {
        // 1. Dynamically fetch the current rule set (Schema Contract)
        $schema = MetaController::getCategoriesSchema();

        // 2. Validate against the dynamic arrays from the YAML file
        $validated = $request->validate([
            'target_meals_per_week' => ['required', 'integer', 'min:1', 'max:21'],
            'default_portions' => ['required', 'integer', 'min:1', 'max:10'],

            'dietary_preferences' => ['nullable', 'array'],
            'dietary_preferences.*' => ['string', Rule::in($schema['diets'] ?? [])],

            'fitness_goals' => ['nullable', 'array'],
            'fitness_goals.*' => ['string', Rule::in($schema['fitness_profiles'] ?? [])],

            'logistics_preferences' => ['nullable', 'array'],
            'logistics_preferences.*' => ['string', Rule::in($schema['logistics'] ?? [])],

            'allergies' => ['nullable', 'array'],
            'allergies.*' => ['string', Rule::in($schema['allergies'] ?? [])],

            'minimize_food_waste' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $user->update($validated);

        // Invalidate the generated plan cache so new preferences apply immediately
        Cache::forget("meal_plan_user_{$user->id}");

        return response()->json([
            'status' => 'success',
            'message' => 'User preferences updated successfully.',
            'data' => [
                'target_meals_per_week' => $user->target_meals_per_week,
                'default_portions' => $user->default_portions,
                'dietary_preferences' => $user->dietary_preferences,
                'fitness_goals' => $user->fitness_goals,
                'logistics_preferences' => $user->logistics_preferences,
                'allergies' => $user->allergies,
                'minimize_food_waste' => (bool) ($user->minimize_food_waste ?? true),
            ],
        ]);
    }
}
