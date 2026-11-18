<?php

use App\Http\Controllers\Branch\DashboardController;
use App\Http\Controllers\Branch\NotificationController;
use App\Http\Controllers\Branch\PackingController;
use App\Http\Controllers\Branch\StockController;
use App\Http\Controllers\Branch\StockEntryController;
use App\Http\Controllers\Branch\StockIssueController;
use App\Http\Controllers\Branch\StockOpnameController;
use App\Http\Controllers\Branch\StockTransferController;
use App\Support\AdminHost;
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
| Served on the panel host only once `ADMIN_DOMAIN` is set (ADR-063); on
| the storefront's host these paths do not exist and answer 404.
|
*/

Route::domain(AdminHost::panelRouteDomain())
    ->middleware(['auth', 'verified', 'branch'])
    ->prefix('branch')
    ->name('branch.')
    ->group(function () {
        Route::get('/', DashboardController::class)
            ->middleware('can:dashboard.branch.view')
            ->name('dashboard');

        /*
         * The signed-in user's own notifications (P1-E4-T9, P2-E6-T4).
         *
         * No permission: a notification belongs to one user and the
         * relationship is what scopes it, so there is nobody else's to name.
         * Here rather than under `/admin` because this group admits every
         * staff account, central or branch, and the bell is in both panels.
         */
        Route::get('notifications', [NotificationController::class, 'index'])
            ->name('notifications.index');

        Route::post('notifications/read', [NotificationController::class, 'markAllRead'])
            ->name('notifications.read');

        Route::post('notifications/{notification}/open', [NotificationController::class, 'open'])
            ->whereUuid('notification')
            ->name('notifications.open');

        /*
         * The packing list (P3-E6-T4): paid orders, split per warehouse and
         * shelf, and the one move warehouse staff make — paid → processing.
         * `{order}` binds through the branch global scope, so another
         * branch's order is a 404; `OrderPolicy::pack` has the last word.
         */
        Route::prefix('packing')->name('packing.')->group(function () {
            Route::get('/', [PackingController::class, 'index'])
                ->middleware('can:orders.pack')
                ->name('index');

            Route::post('{order}/start', [PackingController::class, 'start'])
                ->middleware('can:orders.pack')
                ->name('start');
        });

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

        /*
         * Goods in and goods out (P1-E4-T5, T6).
         *
         * Posting is its own route and its own permission, never a status
         * field on the save: "save the draft" and "move the stock" must not be
         * the same click. Cancelling is only reachable while nothing has
         * moved; reversing posted goods is the opposite document.
         *
         * Goods-out has no delete route at all — an abandoned note is
         * cancelled and stays visible, because a record of something going
         * missing that can be made to disappear is a gap in exactly the trail
         * that matters.
         */
        Route::prefix('stock-entries')->name('stock-entries.')->group(function () {
            Route::get('/', [StockEntryController::class, 'index'])
                ->middleware('can:stock_entries.view')
                ->name('index');

            // Before `{entry}/...`, so "create" is never read as a document id.
            Route::get('create', [StockEntryController::class, 'create'])
                ->middleware('can:stock_entries.create')
                ->name('create');

            Route::post('/', [StockEntryController::class, 'store'])
                ->middleware('can:stock_entries.create')
                ->name('store');

            Route::get('{entry}/edit', [StockEntryController::class, 'edit'])
                ->middleware('can:stock_entries.view')
                ->name('edit');

            Route::put('{entry}', [StockEntryController::class, 'update'])
                ->middleware('can:stock_entries.create')
                ->name('update');

            Route::post('{entry}/post', [StockEntryController::class, 'post'])
                ->middleware('can:stock_entries.post')
                ->name('post');

            Route::post('{entry}/cancel', [StockEntryController::class, 'cancel'])
                ->middleware('can:stock_entries.create')
                ->name('cancel');

            Route::delete('{entry}', [StockEntryController::class, 'destroy'])
                ->middleware('can:stock_entries.delete')
                ->name('destroy');
        });

        Route::prefix('stock-issues')->name('stock-issues.')->group(function () {
            Route::get('/', [StockIssueController::class, 'index'])
                ->middleware('can:stock_issues.view')
                ->name('index');

            Route::get('create', [StockIssueController::class, 'create'])
                ->middleware('can:stock_issues.create')
                ->name('create');

            Route::post('/', [StockIssueController::class, 'store'])
                ->middleware('can:stock_issues.create')
                ->name('store');

            Route::get('{issue}/edit', [StockIssueController::class, 'edit'])
                ->middleware('can:stock_issues.view')
                ->name('edit');

            Route::put('{issue}', [StockIssueController::class, 'update'])
                ->middleware('can:stock_issues.create')
                ->name('update');

            Route::post('{issue}/post', [StockIssueController::class, 'post'])
                ->middleware('can:stock_issues.post')
                ->name('post');

            Route::post('{issue}/cancel', [StockIssueController::class, 'cancel'])
                ->middleware('can:stock_issues.create')
                ->name('cancel');
        });

        /*
         * Stock transfers between branches (P2-E3).
         *
         * One route per step, because each step belongs to a different end of
         * the transfer: the destination requests, cancels and receives; the
         * origin (or central) approves and rejects; the origin ships. The
         * route permission is the first gate, `StockTransferPolicy` the second
         * — it is the policy that knows which end the actor is standing at.
         *
         * `{transfer}` binds through `StockTransferBranchScope`, so a branch
         * that is neither end gets a 404 (P2-E3-T5).
         */
        Route::prefix('stock-transfers')->name('stock-transfers.')->group(function () {
            Route::get('/', [StockTransferController::class, 'index'])
                ->middleware('can:stock_transfers.view')
                ->name('index');

            Route::get('create', [StockTransferController::class, 'create'])
                ->middleware('can:stock_transfers.request')
                ->name('create');

            Route::post('/', [StockTransferController::class, 'store'])
                ->middleware('can:stock_transfers.request')
                ->name('store');

            Route::get('{transfer}', [StockTransferController::class, 'show'])
                ->middleware('can:stock_transfers.view')
                ->name('show');

            Route::post('{transfer}/approve', [StockTransferController::class, 'approve'])
                ->middleware('can:stock_transfers.approve')
                ->name('approve');

            Route::post('{transfer}/reject', [StockTransferController::class, 'reject'])
                ->middleware('can:stock_transfers.approve')
                ->name('reject');

            Route::post('{transfer}/ship', [StockTransferController::class, 'ship'])
                ->middleware('can:stock_transfers.ship')
                ->name('ship');

            Route::post('{transfer}/receive', [StockTransferController::class, 'receive'])
                ->middleware('can:stock_transfers.receive')
                ->name('receive');

            Route::post('{transfer}/cancel', [StockTransferController::class, 'cancel'])
                ->middleware('can:stock_transfers.request')
                ->name('cancel');
        });

        /*
         * Stock counts (P1-E4-T7).
         *
         * Recording counts and completing the session are separate routes with
         * separate permissions, so the person who walked the shelves is not
         * also the person approving what they found (docs/04-rbac.md).
         *
         * There is no edit form: the session's header is fixed once opened,
         * and its lines are a counting sheet rather than a document to retype.
         */
        Route::prefix('stock-opnames')->name('stock-opnames.')->group(function () {
            Route::get('/', [StockOpnameController::class, 'index'])
                ->middleware('can:stock_opnames.view')
                ->name('index');

            Route::post('/', [StockOpnameController::class, 'store'])
                ->middleware('can:stock_opnames.create')
                ->name('store');

            Route::get('{opname}', [StockOpnameController::class, 'show'])
                ->middleware('can:stock_opnames.view')
                ->name('show');

            Route::put('{opname}/counts', [StockOpnameController::class, 'recordCounts'])
                ->middleware('can:stock_opnames.create')
                ->name('counts.update');

            Route::post('{opname}/complete', [StockOpnameController::class, 'complete'])
                ->middleware('can:stock_opnames.complete')
                ->name('complete');

            Route::post('{opname}/cancel', [StockOpnameController::class, 'cancel'])
                ->middleware('can:stock_opnames.create')
                ->name('cancel');
        });
    });
