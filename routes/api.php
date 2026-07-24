<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\ShoppingListController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// --- ÖFFENTLICHE ROUTEN ---
Route::get('/version', [SystemController::class, 'version']);

// Auth & Socialite
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/auth/{provider}/redirect', [AuthController::class, 'redirectToProvider']);
Route::get('/auth/{provider}/callback', [AuthController::class, 'handleProviderCallback']);

// GitHub Webhook
Route::post('/webhooks/github', [WebhookController::class, 'handle']);

// --- GESCHÜTZTE ROUTEN (Benötigen ein Sanctum-Token) ---
Route::middleware('auth:sanctum')->group(function () {

    // Meal Plan
    Route::get('/plan', [PlanController::class, 'current']);
    Route::post('/plan/generate', [PlanController::class, 'generate']);

    // Shopping List
    Route::get('/shopping-list', [ShoppingListController::class, 'index']);

});
