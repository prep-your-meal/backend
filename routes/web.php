<?php

use Illuminate\Support\Facades\Route;

$version = file_exists(base_path('version.txt'))
        ? trim(file_get_contents(base_path('version.txt')))
        : 'latest';

Route::get('/', function () {
    return response()->json([
        'status' => 'success',
        'data' => [
            'name' => config('app.name'),
            'environment' => config('app.env'),
            'status' => 'running',
            'documentation' => url('/documentation'),
            'version' => $version,
        ],
    ]);
});
