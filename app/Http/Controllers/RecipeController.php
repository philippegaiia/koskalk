<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class RecipeController extends Controller
{
    public function index(): View
    {
        return view('recipes.index');
    }

    public function create(): View
    {
        return view('recipes.workbench');
    }
}
