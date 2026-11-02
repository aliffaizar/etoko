<?php

use App\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central Panel
|--------------------------------------------------------------------------
|
| Routes for Super Admin and Admin Pusat. Every route carries its own
| permission check; see docs/04-rbac.md for the matrix.
|
*/

Route::middleware(['auth', 'verified'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', DashboardController::class)
            ->middleware('can:dashboard.central.view')
            ->name('dashboard');
    });
