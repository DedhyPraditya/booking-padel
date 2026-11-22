<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

// Serve the built Vue 3 SPA for all non-API web routes in production/integrated mode
Route::fallback(function () {
    $spaIndex = public_path('spa/index.html');
    if (File::exists($spaIndex)) {
        return response()->file($spaIndex, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    return response()->json([
        'name' => config('app.name', 'PADEL MAKASSAR API'),
        'status' => 'online',
    ]);
});
