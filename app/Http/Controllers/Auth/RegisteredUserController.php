<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicSoapCalculatorController;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Models\ProductFamily;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\RecipeWorkbenchService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(
        RegisterUserRequest $request,
        EntitlementService $entitlementService,
        RecipeWorkbenchService $recipeWorkbenchService,
    ): RedirectResponse {
        $user = User::query()->create($request->validated());

        $entitlementService->assignDefaultPlan($user);
        Auth::login($user);
        $request->session()->regenerate();

        $pendingFormula = $request->session()->pull(PublicSoapCalculatorController::PendingFormulaSessionKey);
        $savedRecipeId = $this->publishPendingFormula($user, $pendingFormula, $recipeWorkbenchService);

        if ($savedRecipeId !== null) {
            return redirect()
                ->route('recipes.edit', $savedRecipeId)
                ->with('status', 'Formula saved.');
        }

        return redirect()->route('dashboard');
    }

    private function publishPendingFormula(User $user, mixed $pendingFormula, RecipeWorkbenchService $recipeWorkbenchService): ?int
    {
        if (! is_array($pendingFormula)) {
            return null;
        }

        $productFamilySlug = $pendingFormula['product_family_slug'] ?? null;
        $draft = $pendingFormula['draft'] ?? null;

        if (! is_string($productFamilySlug) || ! is_array($draft)) {
            return null;
        }

        $productFamily = ProductFamily::query()
            ->where('slug', $productFamilySlug)
            ->first();

        if (! $productFamily instanceof ProductFamily) {
            return null;
        }

        try {
            $recipeVersion = $recipeWorkbenchService->publish($user, $productFamily, $draft);
        } catch (ValidationException|InvalidArgumentException) {
            return null;
        }

        return $recipeVersion->recipe_id;
    }
}
