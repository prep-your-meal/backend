<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class MetaController extends Controller
{
    #[OA\Get(
        path: '/meta/categories',
        summary: 'Get all available preference categories',
        tags: ['System']
    )]
    public function categories(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'diets' => UserPreferenceController::ALLOWED_DIETS,
                'fitness' => UserPreferenceController::ALLOWED_FITNESS,
                'logistics' => UserPreferenceController::ALLOWED_LOGISTICS,
                'allergies' => UserPreferenceController::ALLOWED_ALLERGIES,
            ],
        ]);
    }
}
