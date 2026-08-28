<?php

use Illuminate\Support\Facades\Route;
use Remonode\SDK\Http\Controllers\ApiKeyController;
use Remonode\SDK\Http\Controllers\PortalProvisionController;

// ─── User-facing routes (require sanctum auth) ───────────────
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

// ─── Portal-facing routes (require portal key auth) ──────────
Route::middleware('remonode.portal')->group(function () {
    Route::post('portal/provision', [PortalProvisionController::class, 'provision'])
        ->name('remonode.portal.provision');
    Route::get('portal/keys', [PortalProvisionController::class, 'listKeys'])
        ->name('remonode.portal.keys');
    Route::post('portal/keys/{keyId}/revoke', [PortalProvisionController::class, 'revokeKey'])
        ->name('remonode.portal.keys.revoke');
});
