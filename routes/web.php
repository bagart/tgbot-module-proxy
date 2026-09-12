<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Http\Controllers\ProxyDashboardController;
use BAGArt\ProxyOperations\Http\Controllers\ProxyInventoryController;
use BAGArt\ProxyOperations\Http\Controllers\ProxyJobsController;
use BAGArt\ProxyOperations\Http\Controllers\ProxyPoolsController;
use BAGArt\ProxyOperations\Http\Controllers\ProxySettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'verified'])
    ->prefix('proxy')
    ->name('proxy.')
    ->group(function (): void {
        Route::get('/', [ProxyDashboardController::class, 'index'])->name('dashboard');
        Route::get('inventory', [ProxyInventoryController::class, 'index'])->name('inventory.index');
        Route::delete('inventory/{endpoint}', [ProxyInventoryController::class, 'destroy'])->name('inventory.destroy');
        Route::get('pools', [ProxyPoolsController::class, 'index'])->name('pools.index');
        Route::post('pools', [ProxyPoolsController::class, 'store'])->name('pools.store');
        Route::patch('pools/{pool}', [ProxyPoolsController::class, 'update'])->name('pools.update');
        Route::delete('pools/{pool}', [ProxyPoolsController::class, 'destroy'])->name('pools.destroy');
        Route::post('pools/{pool}/materialize', [ProxyPoolsController::class, 'materialize'])->name('pools.materialize');
        Route::get('settings', [ProxySettingsController::class, 'index'])->name('settings.index');
        Route::put('settings', [ProxySettingsController::class, 'update'])->name('settings.update');
        Route::get('jobs', [ProxyJobsController::class, 'index'])->name('jobs.index');
        Route::post('jobs', [ProxyJobsController::class, 'store'])->name('jobs.store');
    });
