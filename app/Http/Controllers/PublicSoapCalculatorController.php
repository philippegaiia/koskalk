<?php

namespace App\Http\Controllers;

use App\Models\ProductFamily;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use JsonException;

class PublicSoapCalculatorController extends Controller
{
    public const PendingFormulaSessionKey = 'public_calculator.pending_formula';

    public function show(): View
    {
        ProductFamily::query()
            ->where('slug', 'soap')
            ->firstOrFail();

        return view('calculator.show');
    }

    public function storeDraft(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_family_slug' => ['required', 'string', 'exists:product_families,slug'],
            'draft' => ['required', 'string'],
        ]);

        try {
            $draft = json_decode($validated['draft'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return redirect()
                ->route('calculator')
                ->with('status', 'The formula could not be prepared for saving.');
        }

        if (! is_array($draft)) {
            return redirect()
                ->route('calculator')
                ->with('status', 'The formula could not be prepared for saving.');
        }

        $request->session()->put(self::PendingFormulaSessionKey, [
            'product_family_slug' => $validated['product_family_slug'],
            'draft' => $draft,
        ]);

        return redirect()->route('register');
    }
}
