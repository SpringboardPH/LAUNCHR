<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'HR backend is running',
        'data' => [
            'app' => config('app.name'),
            'environment' => config('app.env'),
        ],
    ]);
});
