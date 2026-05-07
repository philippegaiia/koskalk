<section class="overflow-hidden sk-card">
 <div class="flex flex-col gap-3 border-b border-[var(--color-line)] px-5 py-4 lg:flex-row lg:items-start lg:justify-between">
 <div>
 <p class="sk-eyebrow">Restrictions</p>
 <p class="mt-1 text-sm text-[var(--color-ink-soft)]" x-text="`${restrictionRegimeLabel} · ${restrictionBasisLabel}`"></p>
 </div>
 <span class="inline-flex w-fit rounded-full border px-3 py-1.5 text-xs font-semibold" :class="restrictionSummaryStyle(restrictionSummary.status)" x-text="restrictionSummaryLabel"></span>
 </div>

 <template x-if="restrictionRows.length > 0">
 <div class="overflow-x-auto">
 <table class="min-w-full divide-y divide-[var(--color-line)] text-sm">
 <thead class="bg-[var(--color-panel)] text-left text-xs font-semibold tracking-[0.14em] text-[var(--color-ink-soft)] uppercase">
 <tr>
 <th class="px-5 py-3">Substance</th>
 <th class="px-5 py-3">Rule</th>
 <th class="px-5 py-3">Formula %</th>
 <th class="px-5 py-3">Limit</th>
 <th class="px-5 py-3">Status</th>
 <th class="px-5 py-3">Sources</th>
 </tr>
 </thead>
 <tbody class="divide-y divide-[var(--color-line)] bg-[var(--color-panel)]">
 <template x-for="row in restrictionRows" :key="`${row.substance_id}-${row.rule_type}`">
 <tr>
 <td class="px-5 py-4 align-top">
 <p class="font-medium text-[var(--color-ink-strong)]" x-text="row.substance_name"></p>
 <p class="mt-1 text-xs text-[var(--color-ink-soft)]" x-text="row.entity_type"></p>
 </td>
 <td class="px-5 py-4 align-top text-[var(--color-ink-soft)]" x-text="row.rule_type"></td>
 <td class="numeric px-5 py-4 align-top font-medium text-[var(--color-ink-strong)]" x-text="`${format(row.percent_of_formula, 5)}%`"></td>
 <td class="numeric px-5 py-4 align-top text-[var(--color-ink-soft)]" x-text="row.max_percent === null || row.max_percent === undefined ? '—' : `${format(row.max_percent, 5)}%`"></td>
 <td class="px-5 py-4 align-top">
 <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold" :class="restrictionStatusStyle(row.status)" x-text="row.status_label"></span>
 </td>
 <td class="px-5 py-4 align-top text-[var(--color-ink-soft)]">
 <template x-for="source in row.source_ingredients" :key="source">
 <span class="mr-2 inline-flex" x-text="source"></span>
 </template>
 </td>
 </tr>
 </template>
 </tbody>
 </table>
 </div>
 </template>

 <template x-if="restrictionRows.length === 0">
 <div class="px-5 py-6 text-sm text-[var(--color-ink-soft)]">
 No restricted or prohibited substance rules matched the current formula.
 </div>
 </template>

 <template x-if="restrictionWarnings.length > 0">
 <div class="space-y-2 border-t border-[var(--color-line)] px-5 py-4">
 <template x-for="warning in restrictionWarnings" :key="warning">
 <p class="rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)] px-3 py-2 text-xs text-[var(--color-warning-strong)]" x-text="warning"></p>
 </template>
 </div>
 </template>
</section>
