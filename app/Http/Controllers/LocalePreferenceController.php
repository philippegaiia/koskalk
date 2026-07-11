<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLocalePreferenceRequest;
use App\Services\LocalePreferenceResolver;
use Illuminate\Http\RedirectResponse;

class LocalePreferenceController extends Controller
{
    public function __invoke(UpdateLocalePreferenceRequest $request): RedirectResponse
    {
        $locale = $request->validated('locale');
        $request->session()->put(LocalePreferenceResolver::SessionKey, $locale);
        $request->user()?->update(['locale' => $locale]);

        $previous = url()->previous();
        $redirectTo = parse_url($previous, PHP_URL_HOST) === $request->getHost()
            ? $previous
            : ($request->user() ? route('dashboard') : route('home'));

        return redirect($redirectTo)
            ->withCookie(cookie()->forever(LocalePreferenceResolver::CookieName, $locale));
    }
}
