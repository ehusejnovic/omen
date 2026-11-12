<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\GridScannerController;
use App\Http\Controllers\GridTradeController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => to_route('dashboard'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [GridScannerController::class, 'index'])->name('dashboard');
    Route::post('grid-scans', [GridScannerController::class, 'store'])
        ->middleware('throttle:3,10')
        ->name('grid-scans.store');
    Route::resource('journal', GridTradeController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['journal' => 'gridTrade'])
        ->names('grid-trades');
    Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics');
});

require __DIR__.'/settings.php';
