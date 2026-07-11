<?php

namespace App\View\Components;

use App\Models\SupportedLocale;
use App\Services\LocalePreferenceResolver;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\View\Component;

class LanguageSelector extends Component
{
    /**
     * @var Collection<int, SupportedLocale>
     */
    public Collection $locales;

    public function __construct(LocalePreferenceResolver $resolver)
    {
        $this->locales = $resolver->activeLocales();
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.language-selector');
    }
}
