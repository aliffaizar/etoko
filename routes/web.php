<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Shop\AddressController;
use App\Http\Controllers\Shop\BranchController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CatalogController;
use App\Http\Controllers\Shop\HomeController;
use App\Http\Controllers\Shop\ProductController;
use App\Http\Controllers\Shop\WishlistController;
use App\Http\Middleware\ShareStorefrontContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront
|--------------------------------------------------------------------------
|
| Public pages: no auth, because browsing a shop does not need an account.
| The paths are Indonesian because shoppers read them and share them as links;
| route names stay English like the rest of the application.
|
| `ShareStorefrontContext` gives every page the chosen branch and the category
| menu the shop's header is drawn from. It is applied here and not globally so
| the panels do not pay for it (P1-E5-T2).
|
| Prices and stock come from the branch in the session (BR-8), never from the
| signed-in user's `branch_id` — see App\Services\Branch\StorefrontBranch.
|
*/

Route::middleware(ShareStorefrontContext::class)->group(function () {
    // Named `home` rather than `shop.home`: `UserRole::homeRouteName()` and
    // `config('fortify.home')` have pointed at that name since P1-E1-T7, and
    // renaming it would silently change where a customer lands after signing in.
    Route::get('/', HomeController::class)->name('home');

    Route::name('shop.')->group(function () {
        /*
         * The catalogue, the category pages and the search results are one
         * screen with one query behind them; only the pre-applied filter
         * differs.
         *
         * `throttle:catalog-search` limits searching without limiting
         * browsing: the limiter in AppServiceProvider exempts requests that
         * carry no search term, so paging through a category is never
         * throttled.
         */
        Route::get('produk', [CatalogController::class, 'index'])
            ->middleware('throttle:catalog-search')
            ->name('catalog.index');

        Route::get('produk/{slug}', [ProductController::class, 'show'])
            ->name('products.show');

        Route::get('kategori/{category:slug}', [CatalogController::class, 'category'])
            ->middleware('throttle:catalog-search')
            ->name('catalog.category');

        /*
         * Picking a branch is a write — it changes the session — so both
         * routes are non-GET and go through the CSRF check of the `web` group.
         */
        Route::put('cabang', [BranchController::class, 'update'])
            ->name('branch.update');

        Route::post('cabang/terdekat', [BranchController::class, 'nearest'])
            ->name('branch.nearest');
    });
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

/*
|--------------------------------------------------------------------------
| Customer Account (authenticated shoppers)
|--------------------------------------------------------------------------
|
| Paths are in Indonesian so they read as natural URLs in a shop. Names
| stay English for consistency with the rest of the application.
|
| Cart also accepts unauthenticated visitors (guests); the middleware is
| applied per-route below rather than on the whole group.
|
*/

Route::middleware(ShareStorefrontContext::class)->name('shop.')->group(function () {
    // Cart — open to guests and authenticated users
    Route::prefix('keranjang')->name('cart.')->group(function () {
        Route::get('/', [CartController::class, 'index'])->name('index');
        Route::post('/', [CartController::class, 'store'])->name('store');
        Route::patch('{cartItem}', [CartController::class, 'update'])->name('update');
        Route::delete('{cartItem}', [CartController::class, 'destroy'])->name('destroy');
    });

    // Customer account — requires authentication
    Route::middleware(['auth', 'verified'])->prefix('akun')->name('account.')->group(function () {
        Route::prefix('alamat')->name('addresses.')->group(function () {
            Route::get('/', [AddressController::class, 'index'])->name('index');
            Route::post('/', [AddressController::class, 'store'])->name('store');
            Route::patch('{address}', [AddressController::class, 'update'])->name('update');
            Route::delete('{address}', [AddressController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('wishlist')->name('wishlist.')->group(function () {
            Route::get('/', [WishlistController::class, 'index'])->name('index');
            Route::post('{product}/toggle', [WishlistController::class, 'toggle'])->name('toggle');
        });
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
require __DIR__.'/branch.php';
