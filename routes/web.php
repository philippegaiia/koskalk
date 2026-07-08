<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\PackagingItemController;
use App\Http\Controllers\ProductionBatchController;
use App\Http\Controllers\PublicSoapCalculatorController;
use App\Http\Controllers\RecipeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/calculator', [PublicSoapCalculatorController::class, 'show'])->name('calculator');
Route::post('/calculator/draft', [PublicSoapCalculatorController::class, 'storeDraft'])->name('calculator.draft.store');

Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:5,1');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/dashboard/account', [AccountController::class, 'show'])->name('account');
    Route::patch('/dashboard/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile.update');
    Route::patch('/dashboard/account/password', [AccountController::class, 'updatePassword'])->name('account.password.update');
    Route::get('/dashboard/billing/checkout/{plan}', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::post('/dashboard/billing/payment-method', [BillingController::class, 'updatePaymentMethod'])->name('billing.payment-method.update');

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
            Route::post('/{recipe}/production-batches', [ProductionBatchController::class, 'store'])->name('production-batches.store');
            Route::get('/{recipe}/print', 'printSavedRecipe')->name('print.recipe');
            Route::get('/{recipe}/print/production', 'printSavedProductionSheet')->name('print.production');
            Route::get('/{recipe}/print/details', 'printSavedDetails')->name('print.details');
            Route::get('/{recipe}/print/technical', 'printSavedTechnicalSheet')->name('print.technical');
            Route::get('/{recipe}/print/costing', 'printSavedCostingSheet')->name('print.costing');
            Route::get('/{recipe}/export.xlsx', 'exportSavedWorkbook')->name('export.xlsx');
            Route::get('/{recipe}/export.csv', 'exportSavedFormulaCsv')->name('export.csv');
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
            Route::get('/{ingredient}', 'edit')->name('edit');
        });

    Route::controller(PackagingItemController::class)
        ->prefix('/dashboard/packaging-items')
        ->name('packaging-items.')
        ->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('/new', 'create')->name('create');
            Route::get('/{packagingItem}', 'edit')->name('edit');
        });

    Route::view('/dashboard/settings', 'settings')->name('settings');
});
