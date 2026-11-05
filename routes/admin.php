<?php

use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\BranchOperatingHourController;
use App\Http\Controllers\Admin\BranchProductController;
use App\Http\Controllers\Admin\BranchProductPriceController;
use App\Http\Controllers\Admin\BranchStaffController;
use App\Http\Controllers\Admin\BranchWarehouseController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ProductAttributeController;
use App\Http\Controllers\Admin\ProductAttributeValueController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImageController;
use App\Http\Controllers\Admin\ProductImportController;
use App\Http\Controllers\Admin\ProductVariantController;
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
| The `branch` middleware refuses non-staff outright and refuses a branch-bound
| account that has no branch assigned, so the narrowing below always has a
| branch to narrow by.
|
*/

Route::middleware(['auth', 'verified', 'branch'])
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

            Route::prefix('{branch}/products')->name('products.')->group(function () {
                Route::get('/', [BranchProductController::class, 'index'])
                    ->middleware('can:products.view')
                    ->name('index');

                Route::put('/', [BranchProductController::class, 'update'])
                    ->middleware('can:products.update')
                    ->name('update');
            });

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

        /*
         * Branch prices sit outside the scoped-bindings group above: a variant
         * belongs to a product, not to a branch, so scoping `{variant}` to
         * `{branch}` would look for a `Branch::variants()` relation that does
         * not — and should not — exist. `BranchPolicy::viewPrices` and
         * `managePrices` are what keep the branch within the actor's reach.
         */
        Route::prefix('branches/{branch}/prices')->name('branches.prices.')->group(function () {
            Route::get('/', [BranchProductPriceController::class, 'index'])
                ->middleware('can:branch_prices.view')
                ->name('index');

            Route::put('{variant}', [BranchProductPriceController::class, 'store'])
                ->middleware('can:branch_prices.manage')
                ->name('store');

            Route::delete('{variant}', [BranchProductPriceController::class, 'destroy'])
                ->middleware('can:branch_prices.manage')
                ->name('destroy');
        });

        Route::prefix('products')->name('products.')->scopeBindings()->group(function () {
            Route::get('/', [ProductController::class, 'index'])
                ->middleware('can:products.view')
                ->name('index');

            Route::get('create', [ProductController::class, 'create'])
                ->middleware('can:products.create')
                ->name('create');

            // Declared before `{product}/...` so "import" and "export" are
            // never read as a product's route key.
            Route::get('import', [ProductImportController::class, 'show'])
                ->middleware('can:products.create')
                ->name('import.show');

            Route::post('import', [ProductImportController::class, 'store'])
                ->middleware('can:products.create')
                ->name('import.store');

            Route::get('import/template', [ProductImportController::class, 'template'])
                ->middleware('can:products.create')
                ->name('import.template');

            Route::get('export', [ProductImportController::class, 'export'])
                ->middleware('can:products.view')
                ->name('export');

            Route::post('/', [ProductController::class, 'store'])
                ->middleware('can:products.create')
                ->name('store');

            Route::get('{product}/edit', [ProductController::class, 'edit'])
                ->middleware('can:products.update')
                ->name('edit');

            Route::put('{product}', [ProductController::class, 'update'])
                ->middleware('can:products.update')
                ->name('update');

            Route::patch('{product}/status', [ProductController::class, 'updateStatus'])
                ->middleware('can:products.update')
                ->name('status.update');

            Route::delete('{product}', [ProductController::class, 'destroy'])
                ->middleware('can:products.delete')
                ->name('destroy');

            Route::prefix('{product}/images')->name('images.')->group(function () {
                Route::get('/', [ProductImageController::class, 'index'])
                    ->middleware('can:products.view')
                    ->name('index');

                Route::post('/', [ProductImageController::class, 'store'])
                    ->middleware('can:products.update')
                    ->name('store');

                Route::put('order', [ProductImageController::class, 'reorder'])
                    ->middleware('can:products.update')
                    ->name('reorder');

                Route::put('{image}', [ProductImageController::class, 'update'])
                    ->middleware('can:products.update')
                    ->name('update');

                Route::patch('{image}/primary', [ProductImageController::class, 'setPrimary'])
                    ->middleware('can:products.update')
                    ->name('primary.update');

                Route::delete('{image}', [ProductImageController::class, 'destroy'])
                    ->middleware('can:products.update')
                    ->name('destroy');
            });

            Route::prefix('{product}/variants')->name('variants.')->group(function () {
                Route::get('/', [ProductVariantController::class, 'index'])
                    ->middleware('can:products.view')
                    ->name('index');

                Route::post('generate', [ProductVariantController::class, 'generate'])
                    ->middleware('can:products.update')
                    ->name('generate');

                Route::post('/', [ProductVariantController::class, 'store'])
                    ->middleware('can:products.update')
                    ->name('store');

                Route::put('{variant}', [ProductVariantController::class, 'update'])
                    ->middleware('can:products.update')
                    ->name('update');

                Route::patch('{variant}/default', [ProductVariantController::class, 'setDefault'])
                    ->middleware('can:products.update')
                    ->name('default.update');

                Route::delete('{variant}', [ProductVariantController::class, 'destroy'])
                    ->middleware('can:products.update')
                    ->name('destroy');
            });
        });

        Route::prefix('product-attributes')->name('product-attributes.')->scopeBindings()->group(function () {
            Route::get('/', [ProductAttributeController::class, 'index'])
                ->middleware('can:products.view')
                ->name('index');

            Route::post('/', [ProductAttributeController::class, 'store'])
                ->middleware('can:products.create')
                ->name('store');

            Route::put('{attribute}', [ProductAttributeController::class, 'update'])
                ->middleware('can:products.update')
                ->name('update');

            Route::delete('{attribute}', [ProductAttributeController::class, 'destroy'])
                ->middleware('can:products.delete')
                ->name('destroy');

            Route::prefix('{attribute}/values')->name('values.')->group(function () {
                Route::post('/', [ProductAttributeValueController::class, 'store'])
                    ->middleware('can:products.update')
                    ->name('store');

                Route::put('{value}', [ProductAttributeValueController::class, 'update'])
                    ->middleware('can:products.update')
                    ->name('update');

                Route::delete('{value}', [ProductAttributeValueController::class, 'destroy'])
                    ->middleware('can:products.update')
                    ->name('destroy');
            });
        });

        Route::prefix('categories')->name('categories.')->group(function () {
            Route::get('/', [CategoryController::class, 'index'])
                ->middleware('can:categories.view')
                ->name('index');

            Route::get('create', [CategoryController::class, 'create'])
                ->middleware('can:categories.create')
                ->name('create');

            Route::post('/', [CategoryController::class, 'store'])
                ->middleware('can:categories.create')
                ->name('store');

            Route::get('{category}/edit', [CategoryController::class, 'edit'])
                ->middleware('can:categories.update')
                ->name('edit');

            Route::put('{category}', [CategoryController::class, 'update'])
                ->middleware('can:categories.update')
                ->name('update');

            Route::patch('{category}/status', [CategoryController::class, 'updateStatus'])
                ->middleware('can:categories.update')
                ->name('status.update');

            Route::delete('{category}', [CategoryController::class, 'destroy'])
                ->middleware('can:categories.delete')
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
