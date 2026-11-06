<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class ProductOrganizationController extends Controller
{
    public function __invoke(): View
    {
        return view('recipes.organization');
    }
}
