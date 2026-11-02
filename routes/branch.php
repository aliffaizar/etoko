<?php

use App\Http\Controllers\Branch\DashboardController;
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
    });
