<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterUserRequest $request, EntitlementService $entitlementService): RedirectResponse
    {
        $user = User::query()->create($request->validated());

        $entitlementService->assignDefaultPlan($user);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
