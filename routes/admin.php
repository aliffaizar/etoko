<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central Panel
|--------------------------------------------------------------------------
|
| Routes for Super Admin and Admin Pusat. Every route carries its own
| permission check; see docs/04-rbac.md for the matrix.
|
| A branch admin holds `users.view/create/update` for their own staff, so the
| user routes are reachable by them as well. The listing is narrowed to their
| branch and every single-account route passes through UserPolicy (BR-3).
|
*/

Route::middleware(['auth', 'verified'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', DashboardController::class)
            ->middleware('can:dashboard.central.view')
            ->name('dashboard');

        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/', [UserController::class, 'index'])
                ->middleware('can:users.view')
                ->name('index');

            Route::get('create', [UserController::class, 'create'])
                ->middleware('can:users.create')
                ->name('create');

            Route::post('/', [UserController::class, 'store'])
                ->middleware('can:users.create')
                ->name('store');

            Route::get('{user}/edit', [UserController::class, 'edit'])
                ->middleware('can:users.update')
                ->name('edit');

            Route::put('{user}', [UserController::class, 'update'])
                ->middleware('can:users.update')
                ->name('update');

            Route::patch('{user}/status', [UserController::class, 'updateStatus'])
                ->middleware('can:users.update')
                ->name('status.update');

            Route::put('{user}/password', [UserController::class, 'updatePassword'])
                ->middleware('can:users.update')
                ->name('password.update');

            Route::delete('{user}', [UserController::class, 'destroy'])
                ->middleware('can:users.delete')
                ->name('destroy');
        });

        Route::prefix('roles')->name('roles.')->group(function () {
            Route::get('/', [RoleController::class, 'index'])
                ->middleware('can:roles.view')
                ->name('index');

            Route::get('{role}/edit', [RoleController::class, 'edit'])
                ->middleware('can:roles.manage')
                ->name('edit');

            Route::put('{role}', [RoleController::class, 'update'])
                ->middleware('can:roles.manage')
                ->name('update');
        });
    });
