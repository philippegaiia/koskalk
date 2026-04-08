<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class PackagingItemController extends Controller
{
    public function index(): View
    {
        return view('packaging.index');
    }
}
