<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'PrepYourMeal API',
        'status' => 'running',
        'documentation' => url('/documentation'),
    ]);
});
