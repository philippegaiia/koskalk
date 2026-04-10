<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\PackagingItemController;
use App\Http\Controllers\RecipeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

Route::controller(RecipeController::class)
    ->prefix('/dashboard/recipes')
    ->name('recipes.')
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/new', 'create')->name('create');
        Route::delete('/{recipe}', 'destroy')->name('destroy');
        Route::get('/{recipe}/saved', 'saved')->name('saved');
        Route::post('/{recipe}/saved/edit-in-draft', 'editSavedFormulaInDraft')->name('saved.edit-in-draft');
        Route::post('/{recipe}/saved/{version}/restore', 'restoreSavedFormula')->name('saved.restore');
        Route::post('/{recipe}/duplicate', 'duplicate')->name('duplicate');
        Route::get('/{recipe}/print', 'printSavedRecipe')->name('print.recipe');
        Route::get('/{recipe}/print/details', 'printSavedDetails')->name('print.details');
        Route::get('/{recipe}/versions/{version}', 'version')->name('version');
        Route::delete('/{recipe}/versions/{version}', 'destroyVersion')->name('versions.destroy');
        Route::post('/{recipe}/versions/{version}/use-as-draft', 'useVersionAsDraft')->name('use-version-as-draft');
        Route::get('/{recipe}/versions/{version}/print', 'printRecipe')->name('legacy.print.recipe');
        Route::get('/{recipe}/versions/{version}/print/details', 'printDetails')->name('legacy.print.details');
        Route::get('/{recipe}', 'edit')->name('edit');
    });

Route::controller(IngredientController::class)
    ->prefix('/dashboard/ingredients')
    ->name('ingredients.')
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::get('/new', 'create')->name('create');
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
