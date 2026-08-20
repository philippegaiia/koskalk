<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('recipes.start');
        }

        return redirect()->route('login');
    }
}
