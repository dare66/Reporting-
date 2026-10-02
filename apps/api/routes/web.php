<?php

use Illuminate\Support\Facades\Route;

// The API is headless: the Angular app is the user interface.
Route::get('/', fn () => response()->json([
    'name' => 'AIXBI API',
    'api' => url('/api/v1'),
    'health' => url('/up'),
]));
