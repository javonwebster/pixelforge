<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\GenerationImageController;
use App\Http\Controllers\QuoteController;
use Illuminate\Support\Facades\Route;

// No public landing page: sign-up is closed, so guests go to login.
Route::get('/', fn () => to_route(auth()->check() ? 'generations.create' : 'login'))->name('home');

Route::middleware('auth')->group(function () {
    // Fortify's default home; kept so the kit's links still land somewhere.
    Route::get('dashboard', fn () => to_route('generations.create'))->name('dashboard');

    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('generate/quote', QuoteController::class)->middleware('throttle:120,1')->name('generations.quote');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    // whereUuid: a malformed id is a 404, not a Postgres error.
    Route::get('generations/{generation}/download', [GenerationImageController::class, 'download'])
        ->whereUuid('generation')
        ->name('generations.download');

    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('credits', [CreditController::class, 'store'])->name('credits.store');

    Route::get('admin/report', AdminReportController::class)
        ->middleware('can:view-admin-report')
        ->name('admin.report');
});

require __DIR__.'/settings.php';
