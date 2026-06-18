<?php

namespace App\Http\Controllers;

use App\Models\ProductFamily;
use Illuminate\Contracts\View\View;

class PublicSoapCalculatorController extends Controller
{
    public function show(): View
    {
        ProductFamily::query()
            ->where('slug', 'soap')
            ->firstOrFail();

        return view('calculator.show');
    }
}
