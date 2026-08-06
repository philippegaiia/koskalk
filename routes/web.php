<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\BetaInviteAcceptanceController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\LocalePreferenceController;
use App\Http\Controllers\MediaAssetController;
use App\Http\Controllers\MediaAssetDownloadController;
use App\Http\Controllers\MediaAssetPickerMutationController;
use App\Http\Controllers\MediaAssetStatusController;
use App\Http\Controllers\MediaLibraryController;
use App\Http\Controllers\PackagingItemController;
use App\Http\Controllers\ProcurementDocumentController;
use App\Http\Controllers\ProductionBatchController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\RecipeMediaController;
use App\Http\Controllers\UserMediaController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::post('/language', LocalePreferenceController::class)->name('language.update');

Route::middleware('guest')->group(function (): void {
    Route::get('/invite/{token}', [BetaInviteAcceptanceController::class, 'show'])
        ->middleware('throttle:20,1')
        ->name('beta-invites.show');
    Route::post('/invite/{token}', [BetaInviteAcceptanceController::class, 'accept'])
        ->middleware('throttle:beta-invite-accept')
        ->name('beta-invites.accept');

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::view('/email/verify', 'auth.verify-email')->name('verification.notice');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/account', [AccountController::class, 'show'])->name('account');
    Route::patch('/dashboard/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
    Route::patch('/dashboard/account/password', [AccountController::class, 'updatePassword'])
        ->middleware('throttle:5,1')
        ->name('account.password.update');
    Route::get('/dashboard/billing/checkout/{plan}', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::post('/dashboard/billing/payment-method', [BillingController::class, 'updatePaymentMethod'])->name('billing.payment-method.update');
    Route::get('/dashboard/media', [MediaLibraryController::class, 'index'])->name('media.index');
    Route::get('/dashboard/media/picker/assets', [MediaAssetPickerMutationController::class, 'index'])
        ->middleware('throttle:120,1')
        ->name('media.picker-assets');
    Route::get('/dashboard/media/{mediaAsset}/status', MediaAssetStatusController::class)
        ->middleware('throttle:120,1')
        ->name('media.status');
    Route::post('/dashboard/media/{mediaAsset}/retry', [MediaAssetPickerMutationController::class, 'retry'])
        ->middleware('throttle:20,1')
        ->name('media.retry');
    Route::delete('/dashboard/media/{mediaAsset}', [MediaAssetPickerMutationController::class, 'remove'])
        ->middleware('throttle:20,1')
        ->name('media.remove');
    Route::get('/dashboard/media/{mediaAsset}/download', MediaAssetDownloadController::class)
        ->middleware('throttle:120,1')
        ->name('media.download');
    Route::get('/dashboard/media/{mediaAsset}/{conversion}', MediaAssetController::class)
        ->middleware('throttle:240,1')
        ->name('media.show');

    Route::controller(RecipeController::class)
        ->prefix('/dashboard/recipes')
        ->name('recipes.')
        ->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('/new', 'create')->name('create');
            Route::delete('/{recipe}', 'destroy')->name('destroy');
            Route::get('/{recipe}/saved', 'saved')->name('saved');
            Route::post('/{recipe}/saved/edit-current', 'editCurrentFormula')->name('saved.edit-current');
            Route::post('/{recipe}/saved/{version}/restore', 'restorePublishedFormula')->name('saved.restore');
            Route::post('/{recipe}/duplicate', 'duplicate')->name('duplicate');
            Route::post('/{recipe}/lock', 'lock')->name('lock');
            Route::post('/{recipe}/unlock', 'unlock')->name('unlock');
            Route::post('/{recipe}/production-batches', [ProductionBatchController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('production-batches.store');
            Route::get('/{recipe}/print', 'printSavedRecipe')->name('print.recipe');
            Route::get('/{recipe}/print/production', 'printSavedProductionSheet')->name('print.production');
            Route::get('/{recipe}/print/details', 'printSavedDetails')->name('print.details');
            Route::get('/{recipe}/print/technical', 'printSavedTechnicalSheet')->name('print.technical');
            Route::get('/{recipe}/print/costing', 'printSavedCostingSheet')->name('print.costing');
            Route::get('/{recipe}/export.xlsx', 'exportSavedWorkbook')->middleware('throttle:10,1')->name('export.xlsx');
            Route::get('/{recipe}/export.csv', 'exportSavedFormulaCsv')->middleware('throttle:10,1')->name('export.csv');
            Route::get('/{recipe}/media/{path}', [RecipeMediaController::class, 'show'])
                ->where('path', '.*')
                ->middleware('throttle:120,1')
                ->name('media');
            Route::get('/{recipe}/versions/{version}', 'version')->name('version');
            Route::delete('/{recipe}/versions/{version}', 'destroyVersion')->name('versions.destroy');
            Route::post('/{recipe}/versions/{version}/use-as-current', 'restoreCurrentVersion')->name('use-version-as-current');
            Route::get('/{recipe}/versions/{version}/print', 'printRecipe')->name('legacy.print.recipe');
            Route::get('/{recipe}/versions/{version}/print/details', 'printDetails')->name('legacy.print.details');
            Route::get('/{recipe}', 'edit')->name('edit');
        });

    Route::controller(ProductionBatchController::class)
        ->prefix('/dashboard/production-batches')
        ->name('production-batches.')
        ->group(function (): void {
            Route::get('/{productionBatch}', 'show')->name('show');
            Route::patch('/{productionBatch}', 'update')->name('update');
            Route::get('/{productionBatch}/print', 'print')->name('print');
            Route::delete('/{productionBatch}', 'destroy')->name('destroy');
        });

    Route::controller(IngredientController::class)
        ->prefix('/dashboard/ingredients')
        ->name('ingredients.')
        ->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('/new', 'create')->name('create');
            Route::post('/update-price', 'updatePrice')->name('update-price');
            Route::get('/search-platform', 'searchPlatform')->name('search-platform');
            Route::post('/duplicate', 'duplicate')->name('duplicate');
            Route::get('/{ingredient}/media/{path}', [UserMediaController::class, 'ingredient'])
                ->where('path', '.*')
                ->middleware('throttle:120,1')
                ->name('media');
            Route::get('/{ingredient}', 'edit')->name('edit');
        });

    Route::controller(PackagingItemController::class)
        ->prefix('/dashboard/packaging-items')
        ->name('packaging-items.')
        ->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('/new', 'create')->name('create');
            Route::get('/{packagingItem}/media/{path}', [UserMediaController::class, 'packagingItem'])
                ->where('path', '.*')
                ->middleware('throttle:120,1')
                ->name('media');
            Route::get('/{packagingItem}', 'edit')->name('edit');
        });

    Route::view('/dashboard/settings', 'settings')->name('settings');

    Route::prefix('/dashboard/production-bench')
        ->name('production-bench.')
        ->group(function (): void {
            Route::view('/', 'production-bench.home')->name('home');
            Route::view('/inventory', 'production-bench.inventory')->name('inventory');
            Route::view('/production', 'production-bench.production.index')->name('production.index');
            Route::view('/production/new', 'production-bench.production.create')->name('production.create');
            Route::view('/production/settings', 'production-bench.production.settings')->name('production.settings');
            Route::view('/production/settings/numbering', 'production-bench.production.numbering')->name('production.settings.numbering');
            Route::view('/production/settings/batch-sizes/new', 'production-bench.production.batch-size-create')->name('production.settings.presets.create');
            Route::view('/production/settings/batch-sizes/{preset}/edit', 'production-bench.production.batch-size-edit')->name('production.settings.presets.edit');
            Route::view('/production/settings/batch-sizes', 'production-bench.production.batch-size-index')->name('production.settings.presets');
            Route::view('/production/settings/task-sets/new', 'production-bench.production.task-set-create')->name('production.settings.task-sets.create');
            Route::view('/production/settings/task-sets/{taskSet}/edit', 'production-bench.production.task-set-edit')->name('production.settings.task-sets.edit');
            Route::view('/production/settings/task-sets', 'production-bench.production.task-set-index')->name('production.settings.task-sets');
            Route::view('/production/settings/departments', 'production-bench.production.settings')->name('production.settings.departments');
            Route::view('/production/settings/employees', 'production-bench.production.settings')->name('production.settings.employees');
            Route::view('/production/settings/tasks', 'production-bench.production.settings')->name('production.settings.task-types');
            Route::view('/production/settings/calendar', 'production-bench.production.settings')->name('production.settings.calendar');
            Route::view('/production/prepare-stock/{productionRun?}', 'production-bench.production.prepare-stock')->name('production.prepare');
            Route::view('/production/flash', 'production-bench.production.flash')->name('production.flash');
            Route::view('/production/calendar', 'production-bench.production.calendar')->name('production.calendar');
            Route::view('/production/tasks', 'production-bench.production.task-index')->name('production.tasks');
            Route::view('/production/{productionRun}', 'production-bench.production.show')->name('production.show');
            Route::redirect('/purchasing', '/dashboard/production-bench/purchasing/suppliers')->name('purchasing');
            Route::view('/purchasing/suppliers', 'production-bench.purchasing.suppliers')->name('purchasing.suppliers');
            Route::view('/purchasing/suppliers/new', 'production-bench.purchasing.supplier-create')->name('purchasing.suppliers.create');
            Route::view('/purchasing/suppliers/{supplier}/listings/new', 'production-bench.purchasing.supplier-listing-create')->name('purchasing.suppliers.listings.create');
            Route::view('/purchasing/suppliers/{supplier}/edit', 'production-bench.purchasing.supplier-edit')->name('purchasing.suppliers.edit');
            Route::view('/purchasing/suppliers/{supplier}', 'production-bench.purchasing.supplier')->name('purchasing.supplier');
            Route::view('/purchasing/listings/new', 'production-bench.purchasing.supplier-listing-create')->name('purchasing.listings.create');
            Route::view('/purchasing/listings/{listing}/edit', 'production-bench.purchasing.supplier-listing-create')->name('purchasing.listings.edit');
            Route::view('/purchasing/listings', 'production-bench.purchasing.listings')->name('purchasing.listings');
            Route::view('/purchasing/quotation-requests', 'production-bench.purchasing.quotations')->name('purchasing.quotations');
            Route::view('/purchasing/quotation-requests/new', 'production-bench.purchasing.quotation-create')->name('purchasing.quotations.create');
            Route::view('/purchasing/purchase-orders', 'production-bench.purchasing.orders')->name('purchasing.orders');
            Route::view('/purchasing/purchase-orders/new', 'production-bench.purchasing.order-create')->name('purchasing.orders.create');
            Route::view('/purchasing/receipts', 'production-bench.purchasing.receipts')->name('purchasing.receipts');
            Route::view('/purchasing/receipts/new', 'production-bench.purchasing.receipt-create')->name('purchasing.receipts.create');
            Route::view('/purchasing/receipts/{goodsReceipt}', 'production-bench.purchasing.receipt-detail')->name('purchasing.receipts.show');
            Route::view('/purchasing/procurement/{purchaseOrder}', 'production-bench.purchasing.procurement-detail')->name('purchasing.procurement.show');
            Route::get('/purchasing/documents/{purchaseOrder}/print', [ProcurementDocumentController::class, 'show'])
                ->name('purchasing.documents.print');
        });
});
