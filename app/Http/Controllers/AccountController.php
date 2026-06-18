<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountPasswordRequest;
use App\Http\Requests\UpdateAccountProfileRequest;
use App\Services\Billing\PaddleBillingService;
use App\Services\EntitlementService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function show(
        Request $request,
        EntitlementService $entitlementService,
        PaddleBillingService $billing,
    ): View {
        $user = $request->user();

        return view('account.show', [
            'user' => $user,
            'plan' => $entitlementService->planFor($user),
            'usage' => $entitlementService->usageFor($user),
            'billingPlans' => $billing->billablePlans(),
            'billingReady' => $billing->isConfigured(),
            'currentSubscription' => $billing->currentSubscriptionFor($user),
        ]);
    }

    public function updateProfile(UpdateAccountProfileRequest $request): RedirectResponse
    {
        $request->user()?->update($request->validated());

        return redirect()
            ->route('account')
            ->with('profile_status', 'Profile updated.');
    }

    public function updatePassword(UpdateAccountPasswordRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $request->user()?->update([
            'password' => $validated['password'],
        ]);

        return redirect()
            ->route('account')
            ->with('password_status', 'Password updated.');
    }
}
