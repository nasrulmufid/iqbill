<?php

declare(strict_types=1);

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\SumopodWebhookController;
use App\Http\Middleware\VerifySumopodWebhook;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health'])
    ->middleware('throttle:30,1')
    ->name('api.health');

Route::middleware(['web', 'auth', 'active', 'role:super_admin,finance,noc'])
    ->get('/admin/metrics', [HealthController::class, 'metrics'])
    ->name('api.admin.metrics');

Route::post('/webhooks/sumopod', SumopodWebhookController::class)
    ->middleware(['throttle:120,1', VerifySumopodWebhook::class])
    ->name('api.webhooks.sumopod');
