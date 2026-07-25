<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class MediaLibraryController extends Controller
{
    public function index(): View
    {
        return view('media.index');
    }
}
