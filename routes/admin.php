<?php

use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\BranchOperatingHourController;
use App\Http\Controllers\Admin\BranchStaffController;
use App\Http\Controllers\Admin\BranchWarehouseController;
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
| A branch admin holds `users.view/create/update` for their own staff and
| `branches.view/update` plus `warehouses.manage` for their own branch, so the
| user and branch routes are reachable by them as well. Those listings are
| narrowed to their branch and every single-record route passes through
| UserPolicy or BranchPolicy (BR-3).
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

        Route::prefix('branches')->name('branches.')->scopeBindings()->group(function () {
            Route::get('/', [BranchController::class, 'index'])
                ->middleware('can:branches.view')
                ->name('index');

            Route::get('create', [BranchController::class, 'create'])
                ->middleware('can:branches.create')
                ->name('create');

            Route::post('/', [BranchController::class, 'store'])
                ->middleware('can:branches.create')
                ->name('store');

            Route::get('{branch}/edit', [BranchController::class, 'edit'])
                ->middleware('can:branches.update')
                ->name('edit');

            Route::put('{branch}', [BranchController::class, 'update'])
                ->middleware('can:branches.update')
                ->name('update');

            Route::patch('{branch}/status', [BranchController::class, 'updateStatus'])
                ->middleware('can:branches.update')
                ->name('status.update');

            Route::delete('{branch}', [BranchController::class, 'destroy'])
                ->middleware('can:branches.delete')
                ->name('destroy');

            Route::put('{branch}/operating-hours', [BranchOperatingHourController::class, 'update'])
                ->middleware('can:branches.update')
                ->name('operating-hours.update');

            Route::post('{branch}/staff', [BranchStaffController::class, 'store'])
                ->middleware('can:users.update')
                ->name('staff.store');

            Route::prefix('{branch}/warehouses')->name('warehouses.')->group(function () {
                Route::post('/', [BranchWarehouseController::class, 'store'])
                    ->middleware('can:warehouses.manage')
                    ->name('store');

                Route::put('{warehouse}', [BranchWarehouseController::class, 'update'])
                    ->middleware('can:warehouses.manage')
                    ->name('update');

                Route::patch('{warehouse}/default', [BranchWarehouseController::class, 'setDefault'])
                    ->middleware('can:warehouses.manage')
                    ->name('default.update');

                Route::delete('{warehouse}', [BranchWarehouseController::class, 'destroy'])
                    ->middleware('can:warehouses.manage')
                    ->name('destroy');
            });
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
