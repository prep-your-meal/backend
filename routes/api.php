<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomShoppingItemController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\ShoppingListController;
use App\Http\Controllers\Api\UserPreferenceController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// --- Public Routes ---

// Auth & Password Reset (Secured against brute-force)
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login'])->name('login');
    Route::post('/auth/forgot-password', [AuthController::class, 'sendResetLinkEmail'])->name('password.email');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');
});

// Socialite
Route::get('/auth/{provider}/redirect', [AuthController::class, 'redirectToProvider']);
Route::get('/auth/{provider}/callback', [AuthController::class, 'handleProviderCallback']);

// GitHub Webhook
Route::post('/webhooks/github', [WebhookController::class, 'handle']);

// Meta
Route::get('/meta/categories', [MetaController::class, 'categories']);

// Recipes
// List all recipes with pagination and filtering (max 60 requests per minute)
Route::get('/recipes', [RecipeController::class, 'index'])
    ->middleware('throttle:60,1');
// Limit to 30 requests per minute per user/IP to prevent flooding and scraping
Route::get('/recipes/{slug}', [RecipeController::class, 'show'])
    ->middleware('throttle:30,1');

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

// Serve general information about the api
Route::get('/', function () {
    return response()->json([
        'status' => 'success',
        'data' => [
            'name' => config('app.name'),
            'environment' => config('app.env'),
            'status' => 'running',
            'documentation' => route('l5-swagger.default.api'),
            'version' => defined('API_VERSION') ? API_VERSION : '1.0.0', // Safe fallback if constant is missing
        ],
    ]);
})->name('api.info');

// --- Protected Routes ---
Route::middleware('auth:sanctum')->group(function () {

    // Auth & User Profile
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::delete('/user', [AuthController::class, 'destroy']);

    // Meal Plan
    Route::get('/plan', [PlanController::class, 'current']);
    // Limit plan generation to 5 requests per minute per user/IP (auth:sanctum is already inherited)
    Route::post('/plan/generate', [PlanController::class, 'generate'])
        ->middleware('throttle:5,1');
    Route::put('/plan/{date}/swap', [PlanController::class, 'swap']);
    Route::post('/plan/{date}/add', [PlanController::class, 'addManual']); // <--- NEU
    Route::delete('/plan/{date}', [PlanController::class, 'clearDate']);

    // User Preferences (Wizard)
    Route::get('/user/preferences', [UserPreferenceController::class, 'show'])->name('user.preferences.show');
    Route::put('/user/preferences', [UserPreferenceController::class, 'update'])->name('user.preferences.update');

    // Shopping List
    Route::get('/shopping-list', [ShoppingListController::class, 'index']);
    Route::post('/shopping-list/custom', [CustomShoppingItemController::class, 'store']);
    Route::delete('/shopping-list/custom/completed', [CustomShoppingItemController::class, 'clearCompleted']);
    Route::put('/shopping-list/custom/{id}/toggle', [CustomShoppingItemController::class, 'toggle']);
    Route::delete('/shopping-list/custom/{id}', [CustomShoppingItemController::class, 'destroy']);

    // Favorites
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/favorites/{slug}/toggle', [FavoriteController::class, 'toggle']);

});
