<div>
    <div
        x-data="{
            soapQualityPanel: 'bar_cure',
            soapQualitiesExpanded: true,
            soapQualitiesStorageKey: @js('soapkraft:soap-qualities-expanded:' . (auth()->id() ?? 'guest')),
            init() {
                try {
                    const storedValue = localStorage.getItem(this.soapQualitiesStorageKey);
                    this.soapQualitiesExpanded = storedValue === null ? true : storedValue === 'true';
                } catch {
                    this.soapQualitiesExpanded = true;
                }
            },
            toggleSoapQualities() {
                this.soapQualitiesExpanded = ! this.soapQualitiesExpanded;

                try {
                    localStorage.setItem(this.soapQualitiesStorageKey, this.soapQualitiesExpanded.toString());
                } catch {}
            },
        }"
        class="sk-card sk-tone-analysis overflow-hidden"
    >
        <div class="sk-section-header sk-section-header-reference flex flex-col gap-4 border-b border-[var(--color-line)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="text-lg font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.headings.soap_qualities') }} <x-contextual-help.trigger topic="soap.qualities" :topics="$contextualHelp['topics'] ?? []" /></h3>
                <p class="mt-2 max-w-2xl text-xs leading-5 text-[var(--color-ink-soft)]">
                    {{ ($isLiquidSoapWorkbench ?? false) ? __('workbench.qualities.liquid_disclaimer') : __('workbench.qualities.disclaimer') }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-4 sm:justify-end">
                @unless ($isLiquidSoapWorkbench ?? false)
                <div x-cloak class="grid transition-[grid-template-rows,visibility] duration-300 ease-out motion-reduce:transition-none" :class="soapQualitiesExpanded ? 'grid-rows-[1fr]' : 'grid-rows-[0fr] invisible'">
                    <div class="overflow-hidden">
                        <div role="tablist" aria-label="{{ __('workbench.qualities.view_label') }}" class="inline-flex items-center gap-2">
                    <button id="tab-bar-cure" type="button" role="tab" :aria-selected="soapQualityPanel === 'bar_cure'" aria-controls="panel-bar-cure" @click="soapQualityPanel = 'bar_cure'" :class="soapQualityPanel === 'bar_cure' ? 'border-b-[var(--color-ink-strong)] text-[var(--color-ink-strong)]' : 'border-b-[var(--color-line)] text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]'" class="rounded-lg border border-b-2 border-[var(--color-line)] bg-[var(--color-panel)]/35 px-3.5 py-2 text-sm font-semibold transition-colors hover:bg-[var(--color-panel)]/70">
                        {{ __('workbench.qualities.bar_cure') }}
                    </button>
                    <button id="tab-lather-feel" type="button" role="tab" :aria-selected="soapQualityPanel === 'lather_feel'" aria-controls="panel-lather-feel" @click="soapQualityPanel = 'lather_feel'" :class="soapQualityPanel === 'lather_feel' ? 'border-b-[var(--color-ink-strong)] text-[var(--color-ink-strong)]' : 'border-b-[var(--color-line)] text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]'" class="rounded-lg border border-b-2 border-[var(--color-line)] bg-[var(--color-panel)]/35 px-3.5 py-2 text-sm font-semibold transition-colors hover:bg-[var(--color-panel)]/70">
                        {{ __('workbench.qualities.lather_feel') }}
                    </button>
                        </div>
                    </div>
                </div>
                @endunless

                <button
                    type="button"
                    @click="toggleSoapQualities()"
                    :aria-expanded="soapQualitiesExpanded.toString()"
                    aria-controls="soap-quality-content"
                    :aria-label="soapQualitiesExpanded ? t('qualities.hide') : t('qualities.show')"
                    class="sk-quality-disclosure grid size-9 shrink-0 place-items-center rounded-full border border-[var(--color-line-strong)] bg-[var(--color-panel)]/45 text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]/75 hover:text-[var(--color-ink-strong)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-active)]"
                >
                    <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" class="size-4 motion-safe:transition-transform" :class="soapQualitiesExpanded ? 'rotate-180' : ''">
                        <path d="m6 8 4 4 4-4" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" />
                    </svg>
                </button>
            </div>
        </div>

        <div id="soap-quality-content" x-cloak class="grid transition-[grid-template-rows,visibility] duration-300 ease-out motion-reduce:transition-none" :class="soapQualitiesExpanded ? 'grid-rows-[1fr]' : 'grid-rows-[0fr] invisible'">
            <div class="overflow-hidden">
            <template x-if="hasQualityMetricsData">
                <div class="space-y-4 px-5 py-5">
                    @if ($isLiquidSoapWorkbench ?? false)
                    <div class="sk-quality-grid overflow-hidden"><div class="-mr-px -mb-px grid sm:grid-cols-2 xl:grid-cols-4">
                        <template x-for="row in liquidQualityRows()" :key="row.key">
                            <div class="border-r border-b border-[var(--color-line)] bg-white px-4 py-3 text-sm">
                                <span class="block text-xs font-medium leading-4 text-[var(--color-ink-soft)]" x-text="row.label"></span>
                                <div class="mt-2 flex items-baseline justify-between gap-2">
                                    <span class="numeric text-xl font-semibold leading-none text-[var(--color-ink-strong)]" x-text="format(row.value, 1)"></span>
                                    <span class="text-xs font-medium text-[var(--color-ink-soft)]" x-text="row.level"></span>
                                </div>
                                <div class="relative mt-3 h-2 overflow-hidden rounded-full border border-[var(--color-line)] bg-transparent shadow-inner">
                                    <div role="progressbar" :aria-valuenow="Math.round(row.value)" aria-valuemin="0" aria-valuemax="100" :aria-label="row.label" :aria-valuetext="`${format(row.value, 1)}, ${row.level}`" class="relative h-full rounded-full" :style="qualityBarStyle(row.value, 'var(--color-reference-heading)')"></div>
                                </div>
                            </div>
                        </template>
                    </div></div>
                    @else
                    <div id="panel-bar-cure" x-show="soapQualityPanel === 'bar_cure'" role="tabpanel" aria-labelledby="tab-bar-cure" class="sk-quality-grid overflow-hidden"><div class="-mr-px -mb-px grid sm:grid-cols-2 xl:grid-cols-4">
                        <template x-if="barAndCureQualityRows().length === 0">
                            <p>{{ __('workbench.qualities.bar_unavailable') }}</p>
                        </template>
                        <template x-for="row in barAndCureQualityRows()" :key="row.key">
                            <div :class="qualityCardStyle(row.key, row.value)" class="border-r border-b px-4 py-3 text-sm">
                                <span class="block min-h-8 text-xs font-medium leading-4 text-[var(--color-ink-soft)]" x-text="row.label"></span>
                                <div class="numeric mt-1.5 text-xl font-semibold leading-none text-[var(--color-ink-strong)]" x-text="qualityDisplayValue(row)"></div>
                                <div class="relative mt-3 h-2 overflow-hidden rounded-full border border-[var(--color-line)] bg-transparent shadow-inner">
                                    <template x-if="targetZoneStyle(row.key)">
                                        <div class="absolute inset-y-0 rounded-full bg-[var(--color-success-soft)]" :style="targetZoneStyle(row.key)"></div>
                                    </template>
                                    <template x-if="isQualityScored(row.key)">
                                        <div role="progressbar" :aria-valuenow="Math.round(row.value)" aria-valuemin="0" aria-valuemax="100" :aria-label="row.label" class="relative h-full rounded-full" :style="qualityBarStyle(row.value, qualityToneColor(row.key, row.value))"></div>
                                    </template>
                                </div>
                                <template x-if="qualityTargetLabel(row.key)">
                                    <div class="mt-2 text-xs font-medium leading-4 text-[var(--color-ink-soft)]" x-text="qualityTargetLabel(row.key)"></div>
                                </template>
                            </div>
                        </template>
                    </div></div>

                    <div id="panel-lather-feel" x-show="soapQualityPanel === 'lather_feel'" x-cloak role="tabpanel" aria-labelledby="tab-lather-feel" class="sk-quality-grid overflow-hidden"><div class="-mr-px -mb-px grid sm:grid-cols-2 xl:grid-cols-4">
                        <template x-for="row in latherAndFeelQualityRows()" :key="row.key">
                            <div :class="qualityCardStyle(row.key, row.value)" class="border-r border-b px-4 py-3 text-sm">
                                <span class="block min-h-8 text-xs font-medium leading-4 text-[var(--color-ink-soft)]" x-text="row.label"></span>
                                <div class="numeric mt-1.5 text-xl font-semibold leading-none text-[var(--color-ink-strong)]" x-text="qualityDisplayValue(row)"></div>
                                <div class="relative mt-3 h-2 overflow-hidden rounded-full border border-[var(--color-line)] bg-transparent shadow-inner">
                                    <template x-if="targetZoneStyle(row.key)">
                                        <div class="absolute inset-y-0 rounded-full bg-[var(--color-success-soft)]" :style="targetZoneStyle(row.key)"></div>
                                    </template>
                                    <template x-if="isQualityScored(row.key)">
                                        <div role="progressbar" :aria-valuenow="Math.round(row.value)" aria-valuemin="0" aria-valuemax="100" :aria-label="row.label" class="relative h-full rounded-full" :style="qualityBarStyle(row.value, qualityToneColor(row.key, row.value))"></div>
                                    </template>
                                </div>
                                <template x-if="qualityTargetLabel(row.key)">
                                    <div class="mt-2 text-xs font-medium leading-4 text-[var(--color-ink-soft)]" x-text="qualityTargetLabel(row.key)"></div>
                                </template>
                            </div>
                        </template>
                    </div></div>

                    @endif

                    <template x-if="qualityFlags().length > 0">
                        <section aria-label="{{ __('workbench.qualities.review_title') }}" class="border-t border-[var(--color-line)] pt-3">
                            <h4 class="text-sm font-semibold text-[var(--color-ink-strong)]">{{ __('workbench.qualities.review_title') }}</h4>
                            <div class="mt-1.5 divide-y divide-[var(--color-line)]">
                                <template x-for="flag in qualityFlags()" :key="flag.label">
                                    <div class="grid gap-0.5 py-2 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-3">
                                        <div class="text-xs font-medium leading-4 text-[var(--color-ink-strong)]">
                                            <span x-text="flag.label"></span>
                                            @foreach (['soap.qualities.cure', 'soap.qualities.dos', 'soap.qualities.liquid', 'soap.superfat'] as $stateTopic)
                                                <span x-show="flag.helpKey === @js($stateTopic)"><x-contextual-help.trigger :topic="$stateTopic" :topics="$contextualHelp['topics'] ?? []" /></span>
                                            @endforeach
                                        </div>
                                        <div class="text-xs leading-4 text-[var(--color-ink-soft)]" x-text="flag.explanation"></div>
                                    </div>
                                </template>
                            </div>
                        </section>
                    </template>
                </div>
            </template>

            <template x-if="!hasQualityMetricsData">
                <div class="px-5 py-5">
                    <div class="rounded-lg bg-[var(--color-field)] px-4 py-6 text-sm text-[var(--color-ink-soft)]">
                        {{ __('workbench.qualities.empty') }}
                    </div>
                </div>
            </template>
            </div>
        </div>
    </div>
</div>
