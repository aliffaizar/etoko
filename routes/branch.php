<?php

use App\Http\Controllers\Branch\DashboardController;
use App\Http\Controllers\Branch\StockController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Branch Panel
|--------------------------------------------------------------------------
|
| Routes for Admin Cabang and Staff Gudang. The `branch` middleware refuses
| users who are not staff or who have no branch assigned, and every query in
| here is narrowed by the branch global scope (BR-3).
|
*/

Route::middleware(['auth', 'verified', 'branch'])
    ->prefix('branch')
    ->name('branch.')
    ->group(function () {
        Route::get('/', DashboardController::class)
            ->middleware('can:dashboard.branch.view')
            ->name('dashboard');

        /*
         * Stock per warehouse (P1-E4-T3, T4, T8).
         *
         * The warehouse a write aims at travels in the request body, not the
         * URL: both forms act on the warehouse the screen is looking at, and
         * that warehouse is already narrowed to the actor's branch (BR-3).
         *
         * "thresholds" is declared before `{variant}/...` so it is never read
         * as a variant's route key.
         */
        Route::prefix('stocks')->name('stocks.')->group(function () {
            Route::get('/', [StockController::class, 'index'])
                ->middleware('can:stocks.view')
                ->name('index');

            Route::put('thresholds', [StockController::class, 'updateThresholds'])
                ->middleware('can:stocks.adjust')
                ->name('thresholds.update');

            Route::post('{variant}/adjust', [StockController::class, 'adjust'])
                ->middleware('can:stocks.adjust')
                ->name('adjust');

            Route::get('{variant}/movements', [StockController::class, 'movements'])
                ->middleware('can:stocks.view')
                ->name('movements');
        });
    });
