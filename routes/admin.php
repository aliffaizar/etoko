<?php

use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\BranchController;
use App\Http\Controllers\Admin\BranchOperatingHourController;
use App\Http\Controllers\Admin\BranchProductController;
use App\Http\Controllers\Admin\BranchProductPriceController;
use App\Http\Controllers\Admin\BranchStaffController;
use App\Http\Controllers\Admin\BranchWarehouseController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\FeaturedProductController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\ProductAttributeController;
use App\Http\Controllers\Admin\ProductAttributeValueController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductImageController;
use App\Http\Controllers\Admin\ProductImportController;
use App\Http\Controllers\Admin\ProductVariantController;
use App\Http\Controllers\Admin\PromotionController;
use App\Http\Controllers\Admin\RefundController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReportExportController;
use App\Http\Controllers\Admin\ReturnController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\ShipmentController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VoucherController;
use App\Services\Reporting\ReportCatalog;
use App\Support\AdminHost;
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
| Served on the panel host only once `ADMIN_DOMAIN` is set (ADR-063); on
| the storefront's host these paths do not exist and answer 404.
|
*/

Route::domain(AdminHost::panelRouteDomain())
    ->middleware(['auth', 'verified', 'branch'])
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

        /*
         * Vouchers (P2-E5-T2). Marketing is central: every route needs
         * `vouchers.manage` and `VoucherPolicy` also demands a central actor,
         * so the permission granted to a branch role opens nothing.
         */
        Route::prefix('vouchers')->name('vouchers.')->middleware('can:vouchers.manage')->group(function () {
            Route::get('/', [VoucherController::class, 'index'])->name('index');
            Route::get('create', [VoucherController::class, 'create'])->name('create');
            Route::post('/', [VoucherController::class, 'store'])->name('store');
            Route::get('{voucher}/edit', [VoucherController::class, 'edit'])->name('edit');
            Route::put('{voucher}', [VoucherController::class, 'update'])->name('update');
            Route::patch('{voucher}/status', [VoucherController::class, 'updateStatus'])->name('status.update');
            Route::delete('{voucher}', [VoucherController::class, 'destroy'])->name('destroy');
        });

        /*
         * Product promotions (P2-E8-T2, ADR-067). Central only, like vouchers:
         * every route needs `promotions.manage` and `PromotionPolicy` demands a
         * central actor, so the permission granted on the Role screen to a
         * branch role opens nothing. Switched off rather than deleted, so there
         * is no delete route.
         */
        Route::prefix('promotions')->name('promotions.')->middleware('can:promotions.manage')->group(function () {
            Route::get('/', [PromotionController::class, 'index'])->name('index');
            Route::get('create', [PromotionController::class, 'create'])->name('create');
            Route::post('/', [PromotionController::class, 'store'])->name('store');
            Route::get('{promotion}/edit', [PromotionController::class, 'edit'])->name('edit');
            Route::put('{promotion}', [PromotionController::class, 'update'])->name('update');
            Route::patch('{promotion}/status', [PromotionController::class, 'updateStatus'])->name('status.update');
        });

        /*
         * Homepage banners (P2-E8-T1). Central only, like vouchers.
         *
         * Update is a POST rather than a PUT because the form may carry image
         * files, and PHP does not read multipart bodies on PUT.
         */
        Route::prefix('banners')->name('banners.')->middleware('can:banners.manage')->group(function () {
            Route::get('/', [BannerController::class, 'index'])->name('index');
            Route::get('create', [BannerController::class, 'create'])->name('create');
            Route::post('/', [BannerController::class, 'store'])->name('store');
            Route::get('{banner}/edit', [BannerController::class, 'edit'])->name('edit');
            Route::post('{banner}', [BannerController::class, 'update'])->name('update');
            Route::patch('{banner}/status', [BannerController::class, 'updateStatus'])->name('status.update');
            Route::delete('{banner}', [BannerController::class, 'destroy'])->name('destroy');
        });

        /*
         * Featured products on the front page (P2-E8-T4). Catalogue work, so
         * it sits under `products.update` and `ProductPolicy::manageFeatured`
         * keeps it central (BR-1). The whole list is saved at once.
         */
        Route::prefix('featured-products')->name('featured-products.')->group(function () {
            Route::get('/', [FeaturedProductController::class, 'index'])
                ->middleware('can:products.view')
                ->name('index');

            Route::put('/', [FeaturedProductController::class, 'update'])
                ->middleware('can:products.update')
                ->name('update');
        });

        /*
         * Customer reviews (P2-E8-T3, ADR-068). Moderation is central; a branch
         * admin holds `reviews.moderate` too and may answer the reviews of
         * their own branch. `ReviewPolicy` and the `BelongsToBranch` scope are
         * what keep the two apart, not the route.
         */
        Route::prefix('reviews')->name('reviews.')->middleware('can:reviews.moderate')->group(function () {
            Route::get('/', [ReviewController::class, 'index'])->name('index');
            Route::post('{review}/approve', [ReviewController::class, 'approve'])->name('approve');
            Route::post('{review}/reject', [ReviewController::class, 'reject'])->name('reject');
            Route::post('{review}/balas', [ReviewController::class, 'reply'])->name('reply');
        });

        /*
         * The order queue (P1-E7-T8, T9, T10).
         *
         * One set of screens for central and branch staff: `Order` carries the
         * branch global scope, so a branch account's listing is already its
         * own and `OrderPolicy` is the second line behind the 404 that
         * route-model binding gives for another branch's order (ADR-027).
         *
         * Each write is its own route with its own permission, because they
         * are three different decisions: moving an order along, calling it
         * off, and handing it to another branch. `orders.assign_branch` is
         * additionally central-only inside the policy, so granting it on the
         * Role screen does not open it to a branch admin.
         */
        Route::prefix('orders')->name('orders.')->group(function () {
            Route::get('/', [OrderController::class, 'index'])
                ->middleware('can:orders.view')
                ->name('index');

            Route::get('{order}', [OrderController::class, 'show'])
                ->middleware('can:orders.view')
                ->name('show');

            // The order's invoice as a PDF (P2-E6-T2): readable by whoever
            // may read the order, because it holds nothing more.
            Route::get('{order}/invoice', InvoiceController::class)
                ->middleware('can:orders.view')
                ->name('invoice');

            Route::put('{order}/status', [OrderController::class, 'updateStatus'])
                ->middleware('can:orders.update_status')
                ->name('status.update');

            Route::post('{order}/cancel', [OrderController::class, 'cancel'])
                ->middleware('can:orders.cancel')
                ->name('cancel');

            Route::put('{order}/branch', [OrderController::class, 'assignBranch'])
                ->middleware('can:orders.assign_branch')
                ->name('branch.update');

            /*
             * Sending the goods out (P2-E2-T4, T7).
             *
             * Nested under the order rather than under a shipment of their
             * own, because at this point there may be no shipment: an order
             * whose delivery was arranged by hand has no `shipments` row until
             * the branch says which courier it used, and a Click & Collect
             * order never has one at all.
             *
             * Both carry `shipments.manage` rather than an `orders.*`
             * permission — handing goods over is the shipping decision, and
             * the matrix in docs/04-rbac.md puts it there. `OrderPolicy` is
             * the second line, and it also asks the *order* whether it is
             * ready, so a button is never offered for something the server
             * will refuse.
             */
            Route::post('{order}/kirim', [ShipmentController::class, 'dispatch'])
                ->middleware('can:shipments.manage')
                ->name('shipments.dispatch');

            Route::post('{order}/ambil', [ShipmentController::class, 'confirmPickup'])
                ->middleware('can:shipments.manage')
                ->name('pickup.confirm');

            /*
             * Opening a refund against the order (P2-E4-T4).
             *
             * Nested under the order rather than under `refunds`, because at
             * this point there is no refund to authorise against: creating one
             * is the thing being asked for, and an amount only means anything
             * against the order it is a share of. `OrderPolicy::refund` is the
             * second line behind the permission, and it is **central only**
             * explicitly (ADR-049) so the Role screen cannot hand a branch the
             * company's bank account.
             */
            Route::post('{order}/refund', [RefundController::class, 'store'])
                ->middleware('can:refunds.process')
                ->name('refunds.store');
        });

        /*
         * The return queue (P2-E4-T3).
         *
         * One set of screens for central and branch staff, like the order and
         * payment queues: `OrderReturn` carries the branch global scope
         * through a `branch_id` copied from the order (ADR-038), so a branch
         * account's queue is already its own and another branch's return id in
         * the URL answers 404 before `OrderReturnPolicy` is consulted
         * (ADR-027).
         *
         * Reading and deciding are separated by permission, not by screen:
         * `returns.view` sees the queue, while agreeing to take goods back,
         * turning a request down, booking the parcel in and closing it all
         * need `returns.approve`. Those four share one permission because they
         * are the same decision at different points of the same parcel, and
         * the matrix in docs/04-rbac.md has no finer distinction to honour.
         */
        Route::prefix('returns')->name('returns.')->group(function () {
            Route::get('/', [ReturnController::class, 'index'])
                ->middleware('can:returns.view')
                ->name('index');

            Route::get('{return}', [ReturnController::class, 'show'])
                ->middleware('can:returns.view')
                ->name('show');

            Route::post('{return}/approve', [ReturnController::class, 'approve'])
                ->middleware('can:returns.approve')
                ->name('approve');

            Route::post('{return}/reject', [ReturnController::class, 'reject'])
                ->middleware('can:returns.approve')
                ->name('reject');

            Route::post('{return}/receive', [ReturnController::class, 'receive'])
                ->middleware('can:returns.approve')
                ->name('receive');

            Route::post('{return}/complete', [ReturnController::class, 'complete'])
                ->middleware('can:returns.approve')
                ->name('complete');
        });

        /*
         * Money going back (P2-E4-T4).
         *
         * A listing of its own because the brief's admin menu has one, and
         * because it answers what the return queue cannot: which refunds has
         * somebody promised and not yet sent? A branch admin holds
         * `returns.view` and reads it; carrying one out needs
         * `refunds.process`, which is central (ADR-049).
         *
         * Opening a refund is not here but on the order above, because there
         * is no refund row to act on yet.
         */
        Route::prefix('refunds')->name('refunds.')->group(function () {
            Route::get('/', [RefundController::class, 'index'])
                ->middleware('can:returns.view')
                ->name('index');

            Route::post('{refund}/settle', [RefundController::class, 'settle'])
                ->middleware('can:refunds.process')
                ->name('settle');

            Route::post('{refund}/fail', [RefundController::class, 'fail'])
                ->middleware('can:refunds.process')
                ->name('fail');
        });

        /*
         * One parcel, once it exists (P2-E2-T4, T6).
         *
         * No listing and no detail screen: both writes are made from the
         * order's own page, where somebody is standing when they decide to
         * send it. `Shipment` carries the branch global scope through a
         * `branch_id` copied from the order (ADR-038), so another branch's
         * shipment id answers 404 before `ShipmentPolicy` is consulted
         * (ADR-027).
         */
        Route::prefix('shipments')->name('shipments.')->group(function () {
            Route::put('{shipment}/status', [ShipmentController::class, 'updateStatus'])
                ->middleware('can:shipments.manage')
                ->name('status.update');

            Route::post('{shipment}/lacak', [ShipmentController::class, 'refreshTracking'])
                ->middleware('can:shipments.manage')
                ->name('tracking.refresh');
        });

        /*
         * The payment queue (P2-E1-T4).
         *
         * One set of screens for central and branch staff, like the order
         * queue: `Payment` carries the branch global scope through a
         * `branch_id` copied from the order (ADR-038), so a branch account's
         * queue is already its own and another branch's payment id in the URL
         * answers 404 before `PaymentPolicy` is consulted (ADR-027).
         *
         * Reading and deciding are separated by permission, not by screen: a
         * cashier holds `payments.view` and sees the queue, while confirming
         * money or turning a receipt down needs `payments.verify`. All three
         * writes share that permission because they are the same decision from
         * different ends, and the matrix in docs/04-rbac.md has no finer
         * distinction to honour.
         */
        Route::prefix('payments')->name('payments.')->group(function () {
            Route::get('/', [PaymentController::class, 'index'])
                ->middleware('can:payments.view')
                ->name('index');

            Route::get('{payment}', [PaymentController::class, 'show'])
                ->middleware('can:payments.view')
                ->name('show');

            Route::post('{payment}/verify', [PaymentController::class, 'verify'])
                ->middleware('can:payments.verify')
                ->name('verify');

            Route::post('{payment}/reject', [PaymentController::class, 'reject'])
                ->middleware('can:payments.verify')
                ->name('reject');

            Route::post('{payment}/expire', [PaymentController::class, 'expire'])
                ->middleware('can:payments.verify')
                ->name('expire');
        });

        Route::prefix('customers')->name('customers.')->group(function () {
            Route::get('/', [CustomerController::class, 'index'])
                ->middleware('can:customers.view')
                ->name('index');

            Route::get('{customer}', [CustomerController::class, 'show'])
                ->middleware('can:customers.view')
                ->name('show');
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
        /*
         * Reports (P2-E7). Each screen carries the permission of its domain
         * (`ReportCatalog::REPORTS`); branch staff reach the same screens and
         * read their own branch through the branch global scope (BR-3).
         * Exporting needs `reports.export` on top, checked per report by the
         * controller because the report is a route parameter.
         */
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('sales', [ReportController::class, 'sales'])
                ->middleware('can:reports.sales.view')
                ->name('sales');

            Route::get('transactions', [ReportController::class, 'transactions'])
                ->middleware('can:reports.sales.view')
                ->name('transactions');

            Route::get('stock', [ReportController::class, 'stock'])
                ->middleware('can:reports.stock.view')
                ->name('stock');

            Route::get('stock-card', [ReportController::class, 'stockCard'])
                ->middleware('can:reports.stock.view')
                ->name('stock-card');

            Route::get('products', [ReportController::class, 'products'])
                ->middleware('can:reports.product.view')
                ->name('products');

            Route::get('customers', [ReportController::class, 'customers'])
                ->middleware('can:reports.customer.view')
                ->name('customers');

            Route::get('branches', [ReportController::class, 'branches'])
                ->middleware('can:reports.branch.view')
                ->name('branches');

            Route::get('exports/{file}', [ReportExportController::class, 'download'])
                ->middleware('can:reports.export')
                ->where('file', '[a-z0-9][a-z0-9\-]*\.(xlsx|pdf)')
                ->name('exports.download');

            Route::get('{report}/export', [ReportExportController::class, 'export'])
                ->middleware('can:reports.export')
                ->whereIn('report', array_keys(ReportCatalog::REPORTS))
                ->name('export');
        });
    });
