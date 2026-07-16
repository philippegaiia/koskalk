<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptBetaInviteRequest;
use App\Services\BetaInviteService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class BetaInviteAcceptanceController extends Controller
{
    public function show(string $token, BetaInviteService $betaInviteService): View
    {
        $invite = $betaInviteService->findPending($token);

        abort_unless($invite !== null, 404);

        return view('auth.accept-beta-invite', [
            'invite' => $invite,
            'token' => $token,
        ]);
    }

    public function accept(
        string $token,
        AcceptBetaInviteRequest $request,
        BetaInviteService $betaInviteService,
    ): RedirectResponse {
        $user = $betaInviteService->accept($token, $request->validated());

        abort_unless($user !== null, 404);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
