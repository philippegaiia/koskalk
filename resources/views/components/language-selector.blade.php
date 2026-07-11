<form method="POST" action="{{ route('language.update') }}" data-language-selector {{ $attributes->class(['relative inline-flex items-center']) }}>
    @csrf
    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="pointer-events-none absolute left-2.5 z-10 size-4 text-current opacity-65" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7">
        <circle cx="12" cy="12" r="9" />
        <path stroke-linecap="round" d="M3.5 12h17M12 3c2.2 2.45 3.3 5.45 3.3 9S14.2 18.55 12 21M12 3C9.8 5.45 8.7 8.45 8.7 12s1.1 6.55 3.3 9" />
    </svg>
    <label class="sr-only" for="language-selector-{{ $attributes->get('id', 'global') }}">{{ __('public.language.label') }}</label>
    <select
        id="language-selector-{{ $attributes->get('id', 'global') }}"
        name="locale"
        onchange="this.form.submit()"
        class="sk-language-selector-control"
    >
        @foreach ($locales as $locale)
            <option value="{{ $locale->code }}" @selected(app()->getLocale() === $locale->code)>{{ $locale->native_name }}</option>
        @endforeach
    </select>
    <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" class="pointer-events-none absolute right-2.5 size-4 text-current opacity-65" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
        <path stroke-linecap="round" stroke-linejoin="round" d="m7.5 9.5 4.5 4.5 4.5-4.5" />
    </svg>
</form>
