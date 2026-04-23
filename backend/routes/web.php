<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Session\Middleware\StartSession;

Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'HR backend is running',
        'data' => [
            'app' => config('app.name'),
            'environment' => config('app.env'),
        ],
    ]);
})->withoutMiddleware([StartSession::class]);
