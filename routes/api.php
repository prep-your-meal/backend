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

// Serve Swagger assets (CSS, JS, PNG)
Route::get('/docs/asset/{asset}', function ($asset) {
    // Access the physically copied files inside the public directory
    $path = public_path("docs/asset/{$asset}");

    if (file_exists($path)) {
        // Determine the correct MIME type for the requested asset
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $mime = match ($extension) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            default => 'text/plain'
        };

        // Return the static file
        return response()->file($path, ['Content-Type' => $mime]);
    }

    // Return a 404 JSend response if the asset is missing
    abort(404);
});

// Serve the generated JSON documentation for Swagger (static file bypass)
Route::get('/docs/{file?}', function ($file = 'api-docs.json') {
    // Greift auf die kopierte JSON-Datei im public-Ordner zu
    $path = public_path("docs/{$file}");

    if (file_exists($path)) {
        return response()->file($path, ['Content-Type' => 'application/json']);
    }

    abort(404, 'Swagger documentation JSON not found.');
});

// Serve general information about the api
Route::get('/', function () {
    return response()->json([
        'status' => 'success',
        'data' => [
            'name' => config('app.name'),
            'environment' => config('app.env'),
            'status' => 'running',
            'documentation' => url('/documentation'),
            'version' => API_VERSION,
        ],
    ]);
});

// --- Protected routes ---
Route::middleware('auth:sanctum')->group(function () {

    // Meal Plan
    Route::get('/plan', [PlanController::class, 'current']);
    Route::post('/plan/generate', [PlanController::class, 'generate']);

    // Shopping List
    Route::get('/shopping-list', [ShoppingListController::class, 'index']);

});
