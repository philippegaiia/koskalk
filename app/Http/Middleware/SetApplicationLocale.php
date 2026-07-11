<?php

namespace App\Http\Middleware;

use App\Services\LocalePreferenceResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetApplicationLocale
{
    public function __construct(private readonly LocalePreferenceResolver $resolver) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolver->resolve($request);
        App::setLocale($locale);

        if ($request->hasSession()) {
            $request->session()->put(LocalePreferenceResolver::SessionKey, $locale);
        }

        return $next($request);
    }
}
