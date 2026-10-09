<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptBetaInviteRequest;
use App\Models\User;
use App\Services\BetaInviteService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BetaInviteAcceptanceController extends Controller
{
    public function show(string $token, Request $request, BetaInviteService $betaInviteService): View
    {
        $invite = $betaInviteService->findPending($token);

        abort_unless($invite !== null, 404);

        $requiresLogin = $request->user() === null && User::query()->whereRaw('LOWER(email) = ?', [$invite->email])->exists();
        if ($requiresLogin) {
            $request->session()->put('url.intended', route('beta-invites.show', ['token' => $token]));
        }

        return view('auth.accept-beta-invite', [
            'invite' => $invite,
            'token' => $token,
            'requiresLogin' => $requiresLogin,
            'emailMatches' => $request->user() !== null && mb_strtolower(trim($request->user()->email)) === $invite->email,
        ]);
    }

    public function accept(
        string $token,
        AcceptBetaInviteRequest $request,
        BetaInviteService $betaInviteService,
    ): RedirectResponse {
        $user = $betaInviteService->accept($token, $request->validated(), $request->user());

        abort_unless($user !== null, 404);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
