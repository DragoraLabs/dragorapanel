<?php

use App\Http\Controllers\EggController;

// ── Egg System (admin only) ──
Route::prefix('panel/eggs')->middleware('auth:api')->group(function () {
    Route::get('/', [EggController::class, 'index']);
    Route::post('/', [EggController::class, 'store']);
    Route::get('/{egg}', [EggController::class, 'show']);
    Route::put('/{egg}', [EggController::class, 'update']);
    Route::delete('/{egg}', [EggController::class, 'destroy']);

    Route::get('/{egg}/variables', [EggController::class, 'variablesIndex']);
    Route::post('/{egg}/variables', [EggController::class, 'variablesStore']);
    Route::put('/{egg}/variables/{variable}', [EggController::class, 'variablesUpdate']);
    Route::delete('/{egg}/variables/{variable}', [EggController::class, 'variablesDestroy']);
});

// ── Public Egg List (for server creation, no admin required) ──
Route::get('/eggs', [EggController::class, 'active']);
