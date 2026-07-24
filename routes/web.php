<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status' => 'success',
        'data' => [
            'name' => config('app.name'),
            'environment' => config('app.env'),
            'status' => 'running',
            'documentation' => url('/documentation'),
            'version' => '1.0.0',
        ],
    ]);
});
