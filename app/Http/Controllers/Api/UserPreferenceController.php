<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class UserPreferenceController extends Controller
{
    /**
     * Allowed categories based on the defined backend sets.
     */
    private const ALLOWED_DIETS = ['vegan', 'vegetarian', 'keto', 'low-carb', 'gluten-free', 'dairy-free'];

    private const ALLOWED_FITNESS = ['high-protein', 'bulking', 'cutting', 'balanced'];

    private const ALLOWED_LOGISTICS = ['meal-prep-friendly', 'quick', 'one-pot'];

    #[OA\Get(
        path: '/api/user/preferences',
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
                'dietary_preferences' => $user->dietary_preferences ?? [],
                'fitness_goals' => $user->fitness_goals ?? [],
                'logistics_preferences' => $user->logistics_preferences ?? [],
            ],
        ]);
    }

    #[OA\Put(
        path: '/api/user/preferences',
        summary: 'Update user preferences',
        description: 'Update the meal plan preferences for the authenticated user via the frontend wizard.',
        security: [['bearerAuth' => []]],
        tags: ['User']
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['target_meals_per_week'],
            properties: [
                new OA\Property(property: 'target_meals_per_week', type: 'integer', example: 4),
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
            ]
        )
    )]
    #[OA\Response(response: 200, description: 'Preferences updated successfully')]
    #[OA\Response(response: 422, description: 'Validation Error')]
    #[OA\Response(response: 401, description: 'Unauthenticated')]
    public function update(Request $request): JsonResponse
    {
        // Validate the incoming JSON payload against our allowed sets
        $validated = $request->validate([
            'target_meals_per_week' => ['required', 'integer', 'min:1', 'max:21'],

            'dietary_preferences' => ['nullable', 'array'],
            'dietary_preferences.*' => ['string', Rule::in(self::ALLOWED_DIETS)],

            'fitness_goals' => ['nullable', 'array'],
            'fitness_goals.*' => ['string', Rule::in(self::ALLOWED_FITNESS)],

            'logistics_preferences' => ['nullable', 'array'],
            'logistics_preferences.*' => ['string', Rule::in(self::ALLOWED_LOGISTICS)],
        ]);

        $user = $request->user();

        // Update the user record with the validated data
        $user->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'User preferences updated successfully.',
            'data' => [
                'target_meals_per_week' => $user->target_meals_per_week,
                'dietary_preferences' => $user->dietary_preferences,
                'fitness_goals' => $user->fitness_goals,
                'logistics_preferences' => $user->logistics_preferences,
            ],
        ]);
    }
}
