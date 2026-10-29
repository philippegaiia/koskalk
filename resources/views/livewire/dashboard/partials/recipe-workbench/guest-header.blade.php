@php
    $guestCalculator = $workbench['guestCalculator'] ?? [];
    $contextualHelp = $contextualHelp ?? ['topics' => [], 'tabs' => []];
    $registrationUrl = config('app.registration_url');
@endphp

{{-- Guest calculators get a plain page heading instead of the saved-product chrome registered formulas use. --}}
<section aria-labelledby="guest-calculator-heading" class="pb-1">
    <div data-contextual-help-heading class="flex flex-col gap-1">
        <h1 id="guest-calculator-heading" class="text-3xl font-semibold tracking-tight text-[var(--color-ink-strong)]">{{ $guestCalculator['title'] ?? __('calculators.index_title') }}</h1>
        <p class="text-sm text-[var(--color-ink-soft)]">
            {{ __('calculators.guest_notice') }}
            <span x-text="guestStorageMessage"></span>
        </p>
        <x-contextual-help.index-button :help="$contextualHelp" :dynamic-tab="true" />
    </div>

    <div class="mt-5 flex flex-wrap items-end gap-3">
        <label class="flex min-w-[16rem] flex-1 flex-col gap-1.5 text-xs font-medium text-[var(--color-ink-soft)]">
            <span>{{ __('calculators.formula_name') }}</span>
            <input
                x-model="formulaName"
                type="text"
                maxlength="160"
                aria-label="{{ __('calculators.formula_name') }}"
                :placeholder="isCosmeticFormula ? @js(__('workbench.header.untitled_cosmetic')) : @js(__('workbench.header.untitled_soap'))"
                class="sk-input"
            />
        </label>
        <label class="flex flex-col gap-1.5 text-xs font-medium text-[var(--color-ink-soft)]">
            <span>{{ __('number_formats.label') }}</span>
            <select x-model="numberLocale" @change="persistNumberLocale()" aria-label="{{ __('number_formats.label') }}" class="sk-input max-w-[14rem]">
                @foreach (($workbench['numberLocaleOptions'] ?? []) as $locale => $label)
                    <option value="{{ $locale }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <button type="button" @click="requestGuestReset()" class="sk-btn sk-btn-outline min-h-11">{{ __('calculators.start_new') }}</button>
    </div>

    <div x-show="guestResetPending" x-cloak role="dialog" aria-label="{{ __('calculators.start_new') }}" class="mt-3 flex flex-wrap items-center gap-3 rounded-lg border border-[var(--color-line)] px-4 py-3">
        <p class="text-sm text-[var(--color-ink-soft)]">{{ __('calculators.start_new_confirm') }}</p>
        <button type="button" @click="resetGuestCalculation()" class="sk-btn sk-btn-outline">{{ __('calculators.confirm_reset') }}</button>
        <button type="button" @click="cancelGuestReset()" class="sk-btn sk-btn-ghost">{{ __('calculators.cancel') }}</button>
    </div>

    {{-- An untouched empty formula is not an error yet, so the calculation message waits for the first row. --}}
    <div x-show="(calculationPreviewMessage && Object.values(phaseItems).some((rows) => rows.length)) || guestNotices.length" x-cloak class="mt-3 flex flex-col gap-1 text-sm">
        <template x-if="calculationPreviewMessage && Object.values(phaseItems).some((rows) => rows.length)">
            <p role="alert" class="text-[var(--color-danger-strong)]" x-text="calculationPreviewMessage"></p>
        </template>
        <template x-for="(notice, index) in guestNotices" :key="index">
            <p role="status" class="text-[var(--color-ink-soft)]" x-text="notice"></p>
        </template>
    </div>

    @guest
        <aside aria-label="{{ __('calculators.register_heading') }}" data-guest-registration-note class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-[var(--color-line)] bg-[var(--color-panel)] px-4 py-3">
            <p class="max-w-3xl text-sm text-[var(--color-ink-soft)]">{{ __('calculators.register_prompt') }}</p>
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <a href="{{ $registrationUrl }}" class="sk-btn sk-btn-outline min-h-11">{{ __('calculators.register_action') }}</a>
                <a href="{{ route('login') }}" class="font-medium text-[var(--color-accent-strong)] hover:underline">{{ __('calculators.sign_in') }}</a>
            </div>
        </aside>
    @endguest
</section>
