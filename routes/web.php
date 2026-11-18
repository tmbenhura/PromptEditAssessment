<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\GenerationEstimateController;
use App\Http\Controllers\GenerationIndexController;
use App\Http\Controllers\GenerationStatusController;
use App\Http\Controllers\GenerationStoreController;
use App\Http\Controllers\PurchaseCreditController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', GenerationIndexController::class)->name('dashboard');
    Route::post('/generations', GenerationStoreController::class)->name('generations.store');
    Route::post('/generations/estimate', GenerationEstimateController::class)->name('generations.estimate');
    Route::get('/generations/{generation}/status', GenerationStatusController::class)->name('generations.status');

    Route::post('/credits/buy', PurchaseCreditController::class)->name('credits.buy');

    Route::get('/reports', AdminReportController::class)->middleware('admin')->name('admin.reports');
});

require __DIR__.'/settings.php';
