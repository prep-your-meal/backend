<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    description: 'API documentation and testing interface for PrepYourMeal.',
    title: 'PrepYourMeal API'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer'
)]
class SystemController extends Controller
{
    #[OA\Get(
        path: '/api/version',
        summary: 'Get the current API version',
        description: 'Reads the version hash directly from the version.txt file.',
        tags: ['System']
    )]
    #[OA\Response(
        response: 200,
        description: 'Returns the current version string'
    )]
    public function version(): JsonResponse
    {
        $version = 'unknown';
        $basePath = base_path();

        // Fallback for production (e.g., Strato without .git folder)
        if (file_exists("{$basePath}/version.txt")) {
            $version = trim(file_get_contents("{$basePath}/version.txt"));
        }

        return response()->json([
            'status' => 'success',
            'version' => $version,
        ]);
    }
}
