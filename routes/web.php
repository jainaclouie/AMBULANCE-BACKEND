<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'app' => 'Cordova Ambulance Tracker and Dispatch System',
    'api' => url('/api'),
    'status' => 'ok',
]));
