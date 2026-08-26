<?php

use Illuminate\Support\Facades\Route;
use Remonode\SDK\Http\Controllers\ApiKeyController;

Route::prefix('api/v1/remonode')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('api-keys', [ApiKeyController::class, 'index'])
            ->name('remonode.api-keys.index');
        Route::post('api-keys', [ApiKeyController::class, 'store'])
            ->name('remonode.api-keys.store');
        Route::post('api-keys/{keyId}/rotate', [ApiKeyController::class, 'rotate'])
            ->name('remonode.api-keys.rotate');
        Route::post('api-keys/{keyId}/revoke', [ApiKeyController::class, 'revoke'])
            ->name('remonode.api-keys.revoke');
    });
});
