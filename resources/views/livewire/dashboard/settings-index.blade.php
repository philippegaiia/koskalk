<div class="mx-auto max-w-3xl space-y-6">
    <p class="text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('settings.page.intro') }}</p>

    <div class="flex gap-2 border-b border-[var(--color-line)] pb-px">
        <button
            type="button"
            wire:click="$set('activeTab', 'preferences')"
            @class([
                '-mb-px rounded-t-lg border-b-2 px-4 py-2.5 text-sm font-medium transition',
                'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)]' => $activeTab === 'preferences',
                'border-transparent text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]' => $activeTab !== 'preferences',
            ])
        >{{ __('settings.tabs.preferences') }}</button>
        <button
            type="button"
            wire:click="$set('activeTab', 'workspace')"
            @class([
                '-mb-px rounded-t-lg border-b-2 px-4 py-2.5 text-sm font-medium transition',
                'border-[var(--color-ink-strong)] text-[var(--color-ink-strong)]' => $activeTab === 'workspace',
                'border-transparent text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]' => $activeTab !== 'workspace',
            ])
        >{{ __('settings.tabs.workspace') }}</button>
    </div>

    @if ($activeTab === 'preferences')
        <section class="sk-card space-y-5 p-5">
            <div>
                <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('settings.preferences.heading') }}</h3>
                <p class="mt-1 text-sm text-[var(--color-ink-soft)]">{{ __('settings.preferences.description') }}</p>
            </div>

            @if ($preferencesStatus && $preferencesMessage)
                <p role="status" @class([
                    'rounded-lg px-4 py-3 text-sm',
                    'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' => $preferencesStatus === 'success',
                    'bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' => $preferencesStatus === 'error',
                ])>{{ $preferencesMessage }}</p>
            @endif

            <div class="grid gap-3 md:grid-cols-2">
                <label class="sk-inset p-4">
                    <span class="sk-eyebrow">{{ __('public.language.label') }}</span>
                    <select
                        wire:model="locale"
                        class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm font-medium text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
                    >
                        @foreach ($this->localeOptions as $localeCode => $localeName)
                            <option value="{{ $localeCode }}">{{ $localeName }}</option>
                        @endforeach
                    </select>
                    @error('locale')
                        <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                    @enderror
                </label>

                <label class="sk-inset p-4">
                    <span class="sk-eyebrow">{{ __('number_formats.label') }}</span>
                    <select
                        wire:model="numberLocale"
                        class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm font-medium text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
                    >
                        @foreach ($this->numberLocaleOptions as $localeCode => $label)
                            <option value="{{ $localeCode }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('numberLocale')
                        <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                    @enderror
                    <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('number_formats.help') }}</p>
                </label>
            </div>

            <div class="flex justify-end">
                <button
                    wire:click="savePreferences"
                    wire:loading.attr="disabled"
                    type="button"
                    class="rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)] disabled:opacity-50"
                >{{ __('settings.actions.save_preferences') }}</button>
            </div>
        </section>
    @endif

    @if ($activeTab === 'workspace')
        <section class="sk-card space-y-5 p-5">
            <div>
                <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('settings.workspace.heading') }}</h3>
                <p class="mt-1 text-sm leading-6 text-[var(--color-ink-soft)]">{{ __('settings.workspace.description') }}</p>
                <p class="mt-1 text-sm font-medium text-[var(--color-ink-soft)]">{{ __('settings.workspace.owner_help') }}</p>
            </div>

            @if ($workspaceStatus && $workspaceMessage)
                <p role="status" @class([
                    'rounded-lg px-4 py-3 text-sm',
                    'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]' => $workspaceStatus === 'success',
                    'bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]' => $workspaceStatus === 'error',
                ])>{{ $workspaceMessage }}</p>
            @endif

            <div class="grid gap-3 md:grid-cols-2">
                <label class="sk-inset p-4">
                    <span class="sk-eyebrow">{{ __('settings.workspace.name') }}</span>
                    <input
                        wire:model="workspaceName"
                        type="text"
                        class="mt-3 w-full rounded-lg bg-[var(--color-field)] px-3 py-2.5 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
                    >
                    @error('workspaceName')
                        <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                    @enderror
                </label>

                <div class="sk-inset p-4">
                    <span class="sk-eyebrow">{{ __('settings.workspace.default_currency') }}</span>
                    <x-search-combobox
                        id="workspace-currency-search"
                        :label="__('settings.workspace.default_currency')"
                        :options="$currencyOptions"
                        :selected-id="$workspaceCurrency"
                        :placeholder="__('settings.workspace.currency_search')"
                        :allow-empty="false"
                        class="mt-3"
                        x-on:search-combobox-selected="$wire.set('workspaceCurrency', String($event.detail.id))"
                    />
                    @error('workspaceCurrency')
                        <p class="mt-1 text-xs text-[var(--color-danger-strong)]">{{ $message }}</p>
                    @enderror
                    <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]">{{ __('settings.workspace.currency_help') }}</p>
                </div>
            </div>

            <div class="flex justify-end">
                <button
                    wire:click="saveWorkspace"
                    wire:loading.attr="disabled"
                    type="button"
                    class="rounded-full bg-[var(--color-accent)] px-5 py-2.5 text-sm font-medium text-white transition hover:bg-[var(--color-accent-hover)] disabled:opacity-50"
                >{{ __('settings.actions.save_workspace') }}</button>
            </div>
        </section>
    @endif
</div>
