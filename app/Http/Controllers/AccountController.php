<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAccountPasswordRequest;
use App\Http\Requests\UpdateAccountProfileRequest;
use App\Services\Billing\PaddleBillingService;
use App\Services\Billing\PlanPresenter;
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
        PlanPresenter $planPresenter,
    ): View {
        $user = $request->user();
        $plan = $entitlementService->planFor($user);
        $billingPlans = $billing->billablePlans();

        return view('account.show', [
            'user' => $user,
            'plan' => $plan,
            'planPresentation' => $plan === null ? null : $planPresenter->present($plan),
            'usage' => $entitlementService->usageFor($user),
            'billingPlans' => $billingPlans,
            'billingPlanPresentations' => $billingPlans
                ->mapWithKeys(fn ($billingPlan): array => [
                    $billingPlan->id => $planPresenter->present($billingPlan),
                ]),
            'billingReady' => $billing->isConfigured(),
            'currentSubscription' => $billing->currentSubscriptionFor($user),
        ]);
    }

    public function updateProfile(UpdateAccountProfileRequest $request): RedirectResponse
    {
        $request->user()?->update($request->validated());

        return redirect()
            ->route('account')
            ->with('profile_status', __('account.status.profile_updated'));
    }

    public function updatePassword(UpdateAccountPasswordRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $request->user()?->update([
            'password' => $validated['password'],
        ]);

        return redirect()
            ->route('account')
            ->with('password_status', __('account.status.password_updated'));
    }
}
