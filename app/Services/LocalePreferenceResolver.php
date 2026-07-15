<?php

namespace App\Services;

use App\Models\SupportedLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LocalePreferenceResolver
{
    public const CookieName = 'soapkraft_locale';

    public const SessionKey = 'locale';

    /** @var Collection<int, SupportedLocale>|null */
    private ?Collection $activeLocales = null;

    /**
     * @return Collection<int, SupportedLocale>
     */
    public function activeLocales(): Collection
    {
        if ($this->activeLocales instanceof Collection) {
            return $this->activeLocales;
        }

        return $this->activeLocales = SupportedLocale::query()
            ->where('is_active', true)
            ->ordered()
            ->get();
    }

    public function resolve(Request $request): string
    {
        $activeLocales = $this->activeLocales();

        if ($activeLocales->isEmpty()) {
            return app()->getLocale() ?: (string) config('app.fallback_locale', 'en');
        }

        $candidates = [
            $request->user()?->locale,
            $request->hasSession() ? $request->session()->get(self::SessionKey) : null,
            $request->cookie(self::CookieName),
            ...$request->getLanguages(),
            app()->getLocale(),
        ];

        foreach ($candidates as $candidate) {
            if ($locale = $this->match($candidate, $activeLocales)) {
                return $locale;
            }
        }

        return $activeLocales->firstWhere('is_default', true)?->code
            ?? $this->match(config('app.fallback_locale'), $activeLocales)
            ?? $activeLocales->first()->code;
    }

    public function isActive(string $locale): bool
    {
        return $this->match($locale, $this->activeLocales()) !== null;
    }

    /**
     * @param  Collection<int, SupportedLocale>  $activeLocales
     */
    private function match(mixed $candidate, Collection $activeLocales): ?string
    {
        if (! is_string($candidate) || $candidate === '') {
            return null;
        }

        $normalized = str_replace('-', '_', strtolower($candidate));
        $exact = $activeLocales->first(
            fn (SupportedLocale $locale): bool => strtolower($locale->code) === $normalized,
        );

        if ($exact) {
            return $exact->code;
        }

        $base = explode('_', $normalized, 2)[0];

        return $activeLocales->first(
            fn (SupportedLocale $locale): bool => strtolower($locale->code) === $base,
        )?->code;
    }
}
