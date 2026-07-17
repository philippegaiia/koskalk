<div>
    <div x-data="{ soapQualityPanel: 'qualities' }" class="sk-card sk-tone-analysis overflow-hidden">
        <div class="sk-section-header flex flex-col gap-4 border-b border-[var(--color-line)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="sk-eyebrow">Soapkraft qualities</p>
                <h3 class="mt-2 text-lg font-semibold text-[var(--color-ink-strong)]">At a glance</h3>
                <p class="mt-2 max-w-2xl text-xs leading-5 text-[var(--color-ink-soft)]">
                    (Indicative values — additives, process, and cure conditions can change the real soap.)
                </p>
            </div>

            <div role="tablist" aria-label="Quality metrics view" class="inline-flex rounded-[1.15rem] border border-[var(--color-line)] bg-[var(--color-field)] p-1">
                <button id="tab-qualities" type="button" role="tab" :aria-selected="soapQualityPanel === 'qualities'" @click="soapQualityPanel = 'qualities'" :class="soapQualityPanel === 'qualities' ? 'bg-white text-[var(--color-accent)] shadow-sm' : 'text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]'" class="rounded-[0.9rem] px-4 py-2.5 text-sm font-semibold transition">
                    Qualities
                </button>
                <button id="tab-advanced" type="button" role="tab" :aria-selected="soapQualityPanel === 'advanced'" @click="soapQualityPanel = 'advanced'" :class="soapQualityPanel === 'advanced' ? 'bg-white text-[var(--color-accent)] shadow-sm' : 'text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]'" class="rounded-[0.9rem] px-4 py-2.5 text-sm font-semibold transition">
                    Advanced
                </button>
            </div>
        </div>

        <template x-if="hasQualityMetricsData">
            <div class="space-y-4 px-5 py-5">
                <div x-show="soapQualityPanel === 'qualities'" role="tabpanel" aria-labelledby="tab-qualities" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <template x-for="row in defaultQualityRows()" :key="row.key">
                        <div :class="qualityCardStyle(row.key, row.value)" class="rounded-lg border px-4 py-3 text-sm">
                            <span class="block text-sm font-medium leading-5 text-[var(--color-ink-soft)]" x-text="row.label"></span>
                            <div class="numeric mt-2 text-2xl font-semibold leading-none text-[var(--color-ink-strong)]" x-text="qualityDisplayValue(row)"></div>
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
                </div>

                <div x-show="soapQualityPanel === 'advanced'" x-cloak role="tabpanel" aria-labelledby="tab-advanced" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <template x-for="row in advancedQualityRows()" :key="row.key">
                        <div :class="qualityCardStyle(row.key, row.value)" class="rounded-lg border px-4 py-3 text-sm">
                            <span class="block text-sm font-medium leading-5 text-[var(--color-ink-soft)]" x-text="row.label"></span>
                            <div class="numeric mt-2 text-2xl font-semibold leading-none text-[var(--color-ink-strong)]" x-text="qualityDisplayValue(row)"></div>
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
                </div>

                <template x-if="qualityFlags().length > 0">
                    <section aria-label="Formula notes" class="border-t border-[var(--color-line)] pt-3">
                        <p class="sk-eyebrow">Formula notes</p>
                        <div class="mt-1.5 divide-y divide-[var(--color-line)]">
                            <template x-for="flag in qualityFlags()" :key="flag.label">
                                <div class="grid gap-0.5 py-2 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-3">
                                    <div class="text-xs font-medium leading-4 text-[var(--color-ink-strong)]" x-text="flag.label"></div>
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
                    Add saponifiable oils with SAP data to see backend-calculated Soapkraft qualities here.
                </div>
            </div>
        </template>
    </div>
</div>
