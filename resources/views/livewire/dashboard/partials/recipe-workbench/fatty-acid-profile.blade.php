<div class="sk-card sk-tone-analysis overflow-hidden">
 <div class="sk-section-header border-b border-[var(--color-line)] px-5 py-4">
 <div>
 <p class="sk-eyebrow">Fatty acid profile</p>
 </div>
 </div>
 <div class="p-5">

 <template x-if="hasFattyAcidProfileData">
 <div class="mt-4 space-y-4">
 <div class="sk-inset p-4">
 <p class="sk-eyebrow">Grouped profile</p>
 <div class="mt-3 flex h-3 overflow-hidden rounded-full bg-white/80">
 <template x-for="segment in fattyAcidGroupSegments()" :key="segment.key">
 <div class="h-full shrink-0" :style="{ width: `${segment.percent}%`, backgroundColor: segment.color }"></div>
 </template>
 </div>
 <div class="mt-3 grid gap-2">
 <template x-for="segment in fattyAcidGroupSegments()" :key="`${segment.key}-legend`">
 <div class="group/fatty-row relative flex min-w-0 items-center justify-between gap-3 rounded-lg bg-[var(--color-field)] px-3 py-2 text-xs">
 <div class="flex min-w-0 flex-1 items-center gap-2">
 <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: segment.color }"></span>
 <span class="shrink-0 rounded-full px-2 py-0.5 font-medium" :style="{ backgroundColor: segment.softColor, color: segment.textColor }" x-text="segment.shortLabel"></span>
 <span class="min-w-0 flex-1 truncate text-[var(--color-ink-strong)]" x-text="segment.label"></span>
 </div>
 <span class="numeric shrink-0 text-right text-[var(--color-ink-soft)]" x-text="`${format(segment.value, 1)}%`"></span>
 <span aria-hidden="true" class="pointer-events-none absolute inset-y-1 left-2 right-14 z-10 hidden items-center rounded-md bg-[var(--color-ink-strong)] px-3 font-medium text-white opacity-0 shadow-sm transition-opacity motion-reduce:transition-none lg:flex lg:group-hover/fatty-row:opacity-100" x-text="segment.label"></span>
 </div>
 </template>
 </div>
 </div>

 <template x-if="fattyAcidChemistrySummaryRows().length > 0">
 <div class="grid grid-cols-3 gap-2">
 <template x-for="row in fattyAcidChemistrySummaryRows()" :key="row.key">
 <div class="min-w-0 rounded-lg bg-[var(--color-field)] px-2.5 py-2 text-center text-xs">
 <div class="truncate font-medium text-[var(--color-ink-soft)]" x-text="row.label"></div>
 <div class="numeric mt-1 truncate font-semibold text-[var(--color-ink-strong)]" x-text="row.value"></div>
 <template x-if="row.bracket">
 <div class="numeric mt-0.5 truncate text-[11px] leading-4 text-[var(--color-ink-soft)]" x-text="row.bracket"></div>
 </template>
 </div>
 </template>
 </div>
 </template>

 <details class="rounded-lg border border-[var(--color-line)] bg-[var(--color-field)]">
 <summary class="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 marker:hidden">
 <span class="text-xs font-semibold uppercase tracking-[0.14em] text-[var(--color-ink-strong)]">Details</span>
 <span class="numeric shrink-0 text-xs text-[var(--color-ink-soft)]" x-text="`${fattyAcidProfileRows.length} acids`"></span>
 </summary>
 <div class="grid gap-1.5 border-t border-[var(--color-line)] px-3 py-3">
 <template x-for="row in fattyAcidProfileRows" :key="row.key">
 <div class="grid grid-cols-[minmax(0,5.5rem)_minmax(3rem,1fr)_4.25rem] items-center gap-3 rounded-md bg-white/70 px-3 py-2 text-xs">
 <span class="truncate text-[var(--color-ink-soft)]" x-text="row.label"></span>
 <div class="h-1.5 overflow-hidden rounded-full bg-white">
 <div class="h-full rounded-full bg-[var(--color-ink-strong)]" :style="fattyAcidRowBarStyle(row.value, row.color)"></div>
 </div>
 <span class="numeric text-right font-medium text-[var(--color-ink-strong)]" x-text="`${format(row.value, 1)}%`"></span>
 </div>
 </template>
 </div>
 </details>
 </div>
 </template>

 <template x-if="!hasFattyAcidProfileData">
 <div class="mt-4 rounded-lg bg-[var(--color-field)] px-4 py-6 text-sm text-[var(--color-ink-soft)]">
 Fill the fatty acid profile on the selected carrier oils to see the blended profile here.
 </div>
 </template>
 </div>
</div>
