<div class="xl:col-span-2">
 <div class="grid gap-4 xl:grid-cols-2">
 <div class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-5">
 <div class="flex items-center justify-between gap-3">
 <div>
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Koskalk qualities</p>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Compact interpretation first, deeper chemistry second.</p>
 </div>
 <span class="rounded-full border border-[var(--color-line)] px-3 py-1 text-xs font-medium text-[var(--color-ink-soft)]" x-text="isPreviewingCalculation ? 'Updating…' : latherProfileSummary()"></span>
 </div>

 <template x-if="hasQualityMetricsData">
 <div class="mt-4 grid gap-2">
 <template x-for="row in defaultQualityRows()" :key="row.key">
 <div class="rounded-lg bg-[var(--color-field)] px-4 py-3 text-sm">
 <div class="flex items-center justify-between gap-4">
 <span class="text-[var(--color-ink-soft)]" x-text="row.label"></span>
 <div class="text-right">
 <div class="numeric font-medium text-[var(--color-ink-strong)]" x-text="format(row.value, 1)"></div>
 <div class="text-xs text-[var(--color-ink-soft)]" x-text="row.level"></div>
 </div>
 </div>
 <div class="relative mt-3 h-2 overflow-hidden rounded-full bg-white/80">
 <template x-if="targetZoneStyle(row.key)">
 <div class="absolute inset-y-0 rounded-full bg-[var(--color-success-soft)]" :style="targetZoneStyle(row.key)"></div>
 </template>
 <div class="relative h-full rounded-full" :style="qualityBarStyle(row.value)"></div>
 </div>
 <template x-if="row.explanation">
 <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]" x-text="row.explanation"></p>
 </template>
 </div>
 </template>
 </div>
 </template>

 <template x-if="!hasQualityMetricsData">
 <div class="mt-4 rounded-lg bg-[var(--color-field)] px-4 py-6 text-sm text-[var(--color-ink-soft)]">
 Add saponifiable oils with SAP data to see backend-calculated Koskalk qualities here.
 </div>
 </template>

 <template x-if="qualityFlags().length > 0">
 <div class="mt-4 flex flex-wrap gap-2">
 <template x-for="flag in qualityFlags()" :key="flag.label">
 <div class="rounded-lg border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-3 py-2">
 <div class="text-xs font-medium text-[var(--color-ink-strong)]" x-text="flag.label"></div>
 <div class="mt-1 text-xs leading-5 text-[var(--color-ink-soft)]" x-text="flag.explanation"></div>
 </div>
 </template>
 </div>
 </template>
 </div>

 <div class="rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-5">
 <div>
 <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Advanced metrics</p>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Deeper structure signals, including iodine and INS.</p>
 </div>
 <template x-if="hasQualityMetricsData">
 <div class="mt-4 grid gap-2">
 <template x-for="row in advancedQualityRows()" :key="row.key">
 <div class="rounded-lg bg-[var(--color-field)] px-4 py-3 text-sm">
 <div class="flex items-center justify-between">
 <span class="text-[var(--color-ink-soft)]" x-text="row.label"></span>
 <div class="text-right">
 <div class="numeric font-medium text-[var(--color-ink-strong)]" x-text="format(row.value, 1)"></div>
 <template x-if="row.level">
 <div class="text-xs text-[var(--color-ink-soft)]" x-text="row.level"></div>
 </template>
 </div>
 </div>
 <template x-if="row.level">
 <div class="relative mt-3 h-2 overflow-hidden rounded-full bg-white/80">
 <template x-if="targetZoneStyle(row.key)">
 <div class="absolute inset-y-0 rounded-full bg-[var(--color-success-soft)]" :style="targetZoneStyle(row.key)"></div>
 </template>
 <div class="relative h-full rounded-full" :style="qualityBarStyle(row.value)"></div>
 </div>
 </template>
 <template x-if="row.explanation">
 <p class="mt-2 text-xs leading-5 text-[var(--color-ink-soft)]" x-text="row.explanation"></p>
 </template>
 </div>
 </template>
 </div>
 </template>
 <template x-if="!hasQualityMetricsData">
 <div class="mt-4 rounded-lg bg-[var(--color-field)] px-4 py-6 text-sm text-[var(--color-ink-soft)]">
 Add saponifiable oils with SAP data to unlock the deeper chemistry indicators here.
 </div>
 </template>
 </div>
 </div>
</div>
