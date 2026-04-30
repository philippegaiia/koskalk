<div>
    <div x-data="{ soapQualityPanel: 'qualities' }" class="sk-card overflow-hidden">
        <div class="flex flex-col gap-4 border-b border-[var(--color-line)] px-5 py-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="sk-eyebrow">Soapkraft qualities</p>
                <h3 class="mt-2 text-xl font-semibold text-[var(--color-ink-strong)]">At a glance</h3>
                <p class="mt-2 max-w-2xl text-xs leading-5 text-[var(--color-ink-soft)]">
                    (Indicative values — additives, process, and cure conditions can change the real soap.)
                </p>
            </div>

            <div class="inline-flex rounded-[1.15rem] border border-[var(--color-line)] bg-[var(--color-field)] p-1">
                <button type="button" @click="soapQualityPanel = 'qualities'" :class="soapQualityPanel === 'qualities' ? 'bg-white text-[var(--color-accent)] shadow-sm' : 'text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]'" class="rounded-[0.9rem] px-4 py-2 text-sm font-semibold transition">
                    Qualities
                </button>
                <button type="button" @click="soapQualityPanel = 'advanced'" :class="soapQualityPanel === 'advanced' ? 'bg-white text-[var(--color-accent)] shadow-sm' : 'text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]'" class="rounded-[0.9rem] px-4 py-2 text-sm font-semibold transition">
                    Advanced
                </button>
            </div>
        </div>

        <template x-if="hasQualityMetricsData">
            <div class="space-y-4 px-5 py-5">
                <div x-show="soapQualityPanel === 'qualities'" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <template x-for="row in defaultQualityRows()" :key="row.key">
                        <div :class="qualityCardStyle(row.key, row.value)" class="rounded-lg border px-4 py-3 text-sm">
                            <span class="block min-h-10 text-sm font-medium leading-5 text-[var(--color-ink-soft)]" x-text="row.label"></span>
                            <div class="numeric mt-3 text-2xl font-semibold leading-none text-[var(--color-ink-strong)]" x-text="qualityDisplayValue(row)"></div>
                            <div class="relative mt-3 h-2 overflow-hidden rounded-full border border-[var(--color-line)] bg-transparent shadow-inner">
                                <template x-if="targetZoneStyle(row.key)">
                                    <div class="absolute inset-y-0 rounded-full bg-[var(--color-success-soft)]" :style="targetZoneStyle(row.key)"></div>
                                </template>
                                <template x-if="isQualityScored(row.key)">
                                    <div class="relative h-full rounded-full" :style="qualityBarStyle(row.value, qualityToneColor(row.key, row.value))"></div>
                                </template>
                            </div>
                            <template x-if="qualityTargetLabel(row.key)">
                                <div class="mt-2 text-[11px] font-medium leading-4 text-[var(--color-ink-soft)]" x-text="qualityTargetLabel(row.key)"></div>
                            </template>
                        </div>
                    </template>
                </div>

                <div x-show="soapQualityPanel === 'advanced'" x-cloak class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <template x-for="row in advancedQualityRows()" :key="row.key">
                        <div :class="qualityCardStyle(row.key, row.value)" class="rounded-lg border px-4 py-3 text-sm">
                            <span class="block min-h-10 text-sm font-medium leading-5 text-[var(--color-ink-soft)]" x-text="row.label"></span>
                            <div class="numeric mt-3 text-2xl font-semibold leading-none text-[var(--color-ink-strong)]" x-text="qualityDisplayValue(row)"></div>
                            <div class="relative mt-3 h-2 overflow-hidden rounded-full border border-[var(--color-line)] bg-transparent shadow-inner">
                                <template x-if="targetZoneStyle(row.key)">
                                    <div class="absolute inset-y-0 rounded-full bg-[var(--color-success-soft)]" :style="targetZoneStyle(row.key)"></div>
                                </template>
                                <template x-if="isQualityScored(row.key)">
                                    <div class="relative h-full rounded-full" :style="qualityBarStyle(row.value, qualityToneColor(row.key, row.value))"></div>
                                </template>
                            </div>
                            <template x-if="qualityTargetLabel(row.key)">
                                <div class="mt-2 text-[11px] font-medium leading-4 text-[var(--color-ink-soft)]" x-text="qualityTargetLabel(row.key)"></div>
                            </template>
                        </div>
                    </template>
                </div>

                <template x-if="qualityFlags().length > 0">
                    <div class="flex flex-wrap gap-2">
                        <template x-for="flag in qualityFlags()" :key="flag.label">
                            <div class="rounded-lg border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-3 py-2">
                                <div class="text-xs font-medium text-[var(--color-ink-strong)]" x-text="flag.label"></div>
                                <div class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]" x-text="flag.explanation"></div>
                            </div>
                        </template>
                    </div>
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
