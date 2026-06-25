<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\Billing\PaddleBillingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function checkout(Request $request, Plan $plan, PaddleBillingService $billing): View|RedirectResponse
    {
        abort_unless($plan->is_active && $plan->isBillable(), 404);

        if (! $billing->isConfigured()) {
            return redirect()
                ->route('account')
                ->with('billing_status', 'Paddle is installed, but checkout is disabled until the Paddle API key and client-side token are configured.');
        }

        return view('billing.checkout', [
            'plan' => $plan,
            'checkout' => $billing->checkoutFor($request->user(), $plan),
        ]);
    }

    public function updatePaymentMethod(Request $request, PaddleBillingService $billing): RedirectResponse
    {
        if (! $billing->isConfigured()) {
            return redirect()
                ->route('account')
                ->with('billing_status', 'Paddle is installed, but payment method updates are disabled until the Paddle API key and client-side token are configured.');
        }

        $subscription = $billing->currentSubscriptionFor($request->user());

        if (! $subscription) {
            return redirect()
                ->route('account')
                ->with('billing_status', 'No active Paddle subscription is available for payment method updates.');
        }

        return $subscription->redirectToUpdatePaymentMethod();
    }
}
