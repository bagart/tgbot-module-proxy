<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Http\Controllers\ProxyApiController;
use BAGArt\ProxyOperations\Http\Laravel\Controllers\BotCommandController;
use BAGArt\ProxyOperations\Http\Laravel\Controllers\DecisionController;
use BAGArt\ProxyOperations\Http\Laravel\Controllers\GatewayController;
use BAGArt\ProxyOperations\Http\Laravel\Controllers\HealthController;
use BAGArt\ProxyOperations\Http\Laravel\Controllers\IncidentController;
use BAGArt\ProxyOperations\Http\Laravel\Controllers\MagicLinkController;
use BAGArt\ProxyOperations\Http\Laravel\Middleware\GatewayAuthMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('health')->group(function (): void {
    Route::get('/live', [HealthController::class, 'live'])->name('proxy.health.live');
    Route::get('/ready', [HealthController::class, 'ready'])->name('proxy.health.ready');
    Route::get('/detailed', [HealthController::class, 'detailed'])->name('proxy.health.detailed');
});

Route::prefix('api/v1')->group(function (): void {
    Route::get('/proxies', [ProxyApiController::class, 'listProxies']);
    Route::post('/proxies/import', [ProxyApiController::class, 'importProxy']);
    Route::get('/proxies/{id}', [ProxyApiController::class, 'getProxy']);
    Route::delete('/proxies/{id}', [ProxyApiController::class, 'deleteProxy']);
    Route::get('/proxies/export', [ProxyApiController::class, 'exportProxies']);

    Route::post('/audit/start', [ProxyApiController::class, 'startAudit']);
    Route::get('/audit/{jobId}', [ProxyApiController::class, 'auditStatus']);

    Route::get('/pools', [ProxyApiController::class, 'listPools']);

    Route::get('/settings', [ProxyApiController::class, 'getSettings']);
    Route::put('/settings', [ProxyApiController::class, 'updateSettings']);

    Route::prefix('incidents')->group(function (): void {
        Route::get('/', [IncidentController::class, 'index']);
        Route::get('/{id}', [IncidentController::class, 'show']);
        Route::post('/detect', [IncidentController::class, 'detect']);
        Route::post('/{id}/transition', [IncidentController::class, 'transition']);
        Route::post('/{id}/note', [IncidentController::class, 'addNote']);
    });

    Route::prefix('decisions')->group(function (): void {
        Route::get('/', [DecisionController::class, 'index']);
        Route::post('/', [DecisionController::class, 'store']);
        Route::get('/{entityType}/{entityId}/timeline', [DecisionController::class, 'timeline']);
    });
});

Route::prefix('proxy-operations/auth')->group(function (): void {
    Route::post('/magic-link/request', [MagicLinkController::class, 'request']);
    Route::get('/magic-link/verify', [MagicLinkController::class, 'verify']);
});

Route::prefix('proxy-operations/bot')->group(function (): void {
    Route::post('/command', BotCommandController::class);
});

Route::prefix('api/v2/proxy')->middleware(GatewayAuthMiddleware::class)->group(function (): void {
    Route::get('/list', [GatewayController::class, 'listProxies']);
    Route::post('/import', [GatewayController::class, 'importProxies']);
    Route::post('/assign', [GatewayController::class, 'assignProxy']);
    Route::post('/release', [GatewayController::class, 'releaseProxy']);
    Route::post('/report', [GatewayController::class, 'reportResult']);
});
