<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IngredientController;
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
        Route::get('/{recipe}/versions/{version}', 'version')->name('version');
        Route::post('/{recipe}/versions/{version}/use-as-draft', 'useVersionAsDraft')->name('use-version-as-draft');
        Route::get('/{recipe}/versions/{version}/print', 'printRecipe')->name('print.recipe');
        Route::get('/{recipe}/versions/{version}/print/details', 'printDetails')->name('print.details');
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
