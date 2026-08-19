<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\ShoppingListController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// Auth & Socialite
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/auth/{provider}/redirect', [AuthController::class, 'redirectToProvider']);
Route::get('/auth/{provider}/callback', [AuthController::class, 'handleProviderCallback']);

// GitHub Webhook
Route::post('/webhooks/github', [WebhookController::class, 'handle']);

/*
|--------------------------------------------------------------------------
| Shared hosting environment FIX: Swagger UI Assets & Documentation
|--------------------------------------------------------------------------
|
| Manually serve Swagger UI assets and the generated JSON documentation
| under the /api prefix. This ensures compatibility with shared hosting
| subfolder routing and bypasses strict open_basedir restrictions.
|
*/

// / Serve Swagger assets (CSS, JS, PNG)
Route::get('/docs/asset/{asset}', function ($asset) {
    // List of all possible storage locations (Strato + local vendor directories)
    $paths = [
        public_path("docs/asset/{$asset}"),
        base_path("vendor/swagger-api/swagger-ui/dist/{$asset}"), // Default path
        base_path("vendor/darkaonline/l5-swagger/ui/dist/{$asset}"), // Alternative L5 version path
    ];

    foreach ($paths as $path) {
        if (file_exists($path)) {
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            $mime = match ($extension) {
                'css' => 'text/css',
                'js' => 'application/javascript',
                'png' => 'image/png',
                default => 'text/plain'
            };

            return response()->file($path, ['Content-Type' => $mime]);
        }
    }

    // Temporary debug output: Shows exactly where Laravel searched
    return response()->json([
        'status' => 'error',
        'message' => 'Swagger asset locally missing.',
        'asset_requested' => $asset,
        'searched_paths' => $paths,
    ], 404);
})->name('l5-swagger.default.asset')->where('asset', '.*'); // <--- IMPORTANT: Allows file extensions in the route parameter

// Serve the generated JSON documentation for Swagger (static file bypass)
Route::get('/docs/{file?}', function ($file = 'api-docs.json') {
    // 1. Check public path (Production / Strato workaround)
    $path = public_path("docs/{$file}");

    // 2. Fallback to storage path (Local Development / Sail)
    if (! file_exists($path)) {
        $path = storage_path("api-docs/{$file}");
    }

    if (file_exists($path)) {
        return response()->file($path, ['Content-Type' => 'application/json']);
    }

    abort(404, 'Swagger documentation JSON not found.');
})->name('l5-swagger.default.docs');

// Serve the generated JSON documentation for Swagger (static file bypass)
Route::get('/docs/{file?}', function ($file = 'api-docs.json') {
    // 1. Check public path (Production / Strato Workaround)
    $path = public_path("docs/{$file}");

    // 2. Fallback to storage path (Local Development / Sail)
    if (! file_exists($path)) {
        $path = storage_path("api-docs/{$file}");
    }

    if (file_exists($path)) {
        return response()->file($path, ['Content-Type' => 'application/json']);
    }

    abort(404, 'Swagger documentation JSON not found.');
})->name('l5-swagger.default.asset');

// Serve general information about the api
Route::get('/', function () {
    return response()->json([
        'status' => 'success',
        'data' => [
            'name' => config('app.name'),
            'environment' => config('app.env'),
            'status' => 'running',
            'documentation' => route('l5-swagger.default.api'),
            'version' => API_VERSION,
        ],
    ]);
})->name('l5-swagger.default.docs');

// --- Protected routes ---
Route::middleware('auth:sanctum')->group(function () {

    // Meal Plan
    Route::get('/plan', [PlanController::class, 'current']);
    Route::post('/plan/generate', [PlanController::class, 'generate']);

    // Shopping List
    Route::get('/shopping-list', [ShoppingListController::class, 'index']);

});
