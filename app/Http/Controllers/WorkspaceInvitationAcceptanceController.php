<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptWorkspaceInvitationRequest;
use App\Models\User;
use App\Services\WorkspaceInvitationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspaceInvitationAcceptanceController extends Controller
{
    public function show(string $token, Request $request, WorkspaceInvitationService $invitations): View
    {
        $invitation = $invitations->findPending($token);
        abort_unless($invitation !== null, 404);
        $requiresLogin = $request->user() === null && User::query()->whereRaw('LOWER(email) = ?', [$invitation->email])->exists();
        if ($requiresLogin) {
            $request->session()->put('url.intended', route('workspace-invitations.show', ['token' => $token]));
        }

        return view('auth.accept-workspace-invitation', [
            'invitation' => $invitation, 'token' => $token, 'requiresLogin' => $requiresLogin,
            'emailMatches' => $request->user() !== null && mb_strtolower(trim($request->user()->email)) === $invitation->email,
        ]);
    }

    public function accept(string $token, AcceptWorkspaceInvitationRequest $request, WorkspaceInvitationService $invitations): RedirectResponse
    {
        $wasGuest = $request->user() === null;
        $user = $invitations->accept($token, $request->user(), $request->validated());
        if ($wasGuest) {
            auth()->login($user);
            $request->session()->regenerate();
        }

        return redirect()->route('dashboard');
    }
}
