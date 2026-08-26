<?php

use Illuminate\Support\Facades\Route;
use Remonode\SDK\Http\Controllers\RemonodeWebhookController;
use Remonode\SDK\Http\Middleware\VerifyRemonodeWebhook;

Route::post('remonode/events', [RemonodeWebhookController::class, 'handleWebhook'])
    ->middleware(VerifyRemonodeWebhook::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('remonode.webhook');
