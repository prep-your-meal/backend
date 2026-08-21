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

// Socialite OAuth
Route::get('/auth/{provider}/redirect', [AuthController::class, 'redirectToProvider']);
Route::get('/auth/{provider}/callback', [AuthController::class, 'handleProviderCallback']);

// GitHub Webhook for recipe synchronization
Route::post('/webhooks/github', [WebhookController::class, 'handle']);

// Meta data (categories, diets, etc.)
Route::get('/meta/categories', [MetaController::class, 'categories']);

// Recipes
// List all recipes with pagination and filtering (max 60 requests per minute)
Route::get('/recipes', [RecipeController::class, 'index'])->middleware('throttle:60,1');
// Limit to 30 requests per minute per user/IP to prevent flooding and scraping
Route::get('/recipes/{slug}', [RecipeController::class, 'show'])->middleware('throttle:30,1');

// Serve Recipe Images (Strato File Bypass)
// Intercepts the image request to bypass restrictive web server configurations.
// IMPORTANT: No ->name() is assigned here to avoid any caching collisions!
Route::get('/recipes/images/{filename}', function ($filename) {
    $path = public_path("recipes/images/{$filename}");

    if (file_exists($path)) {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $mime = match ($extension) {
            'webp' => 'image/webp',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream'
        };

        return response()->file($path, ['Content-Type' => $mime]);
    }

    abort(404, 'Recipe image not found on server.');
});

// Serve general information about the API
Route::get('/', function () {
    return response()->json([
        'status' => 'success',
        'data' => [
            'name' => config('app.name'),
            'environment' => config('app.env'),
            'status' => 'running',
            'documentation' => route('l5-swagger.default.api'),
            'version' => defined('API_VERSION') ? API_VERSION : '1.0.0',
        ],
    ]);
})->name('api.info');

// --- Protected Routes ---
Route::middleware('auth:sanctum')->group(function () {

    // Auth Logout
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // User Profile & Preferences
    Route::prefix('user')->group(function () {
        Route::get('/', [AuthController::class, 'me']);
        Route::delete('/', [AuthController::class, 'destroy']);
        Route::get('/preferences', [UserPreferenceController::class, 'show'])->name('user.preferences.show');
        Route::put('/preferences', [UserPreferenceController::class, 'update'])->name('user.preferences.update');
    });

    // Meal Plan
    Route::prefix('plan')->group(function () {
        Route::get('/', [PlanController::class, 'current']);
        Route::post('/generate', [PlanController::class, 'generate'])->middleware('throttle:5,1');
        Route::put('/{date}/swap', [PlanController::class, 'swap']);
        Route::post('/{date}/add', [PlanController::class, 'addManual']);
        Route::delete('/{date}', [PlanController::class, 'clearDate']);
    });

    // Shopping List
    Route::prefix('shopping-list')->group(function () {
        Route::get('/', [ShoppingListController::class, 'index']);
        Route::post('/custom', [CustomShoppingItemController::class, 'store']);
        Route::delete('/custom/completed', [CustomShoppingItemController::class, 'clearCompleted']);
        Route::put('/custom/{id}/toggle', [CustomShoppingItemController::class, 'toggle']);
        Route::delete('/custom/{id}', [CustomShoppingItemController::class, 'destroy']);
    });

    // Favorites
    Route::prefix('favorites')->group(function () {
        Route::get('/', [FavoriteController::class, 'index']);
        Route::post('/{slug}/toggle', [FavoriteController::class, 'toggle']);
    });

});
