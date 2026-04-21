<nav class="grid gap-2 xl:grid-cols-5">
 <button
 type="button"
 @click="activeWorkbenchTab = 'formula'"
 :class="activeWorkbenchTab === 'formula'
 ? 'border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-ink-strong)] border-t-2'
 : 'border border-[var(--color-line)] bg-white text-[var(--color-ink-soft)] hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]'"
 class="rounded-lg px-5 py-3.5 text-left text-base font-semibold transition"
 >
 Formula
 </button>

 <button
 type="button"
 @click="activeWorkbenchTab = 'packaging'"
 :class="activeWorkbenchTab === 'packaging'
 ? 'border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-ink-strong)] border-t-2'
 : 'border border-[var(--color-line)] bg-white text-[var(--color-ink-soft)] hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]'"
 class="rounded-lg px-5 py-3.5 text-left text-base font-semibold transition"
 >
 Packaging
 </button>

 <button
 type="button"
 @click="activeWorkbenchTab = 'costing'; ensureCostingLoaded()"
 :class="activeWorkbenchTab === 'costing'
 ? 'border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-ink-strong)] border-t-2'
 : 'border border-[var(--color-line)] bg-white text-[var(--color-ink-soft)] hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]'"
 class="rounded-lg px-5 py-3.5 text-left text-base font-semibold transition"
 >
 Costing
 </button>

 <button
 type="button"
 @click="activeWorkbenchTab = 'output'"
 :class="activeWorkbenchTab === 'output'
 ? 'border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-ink-strong)] border-t-2'
 : 'border border-[var(--color-line)] bg-white text-[var(--color-ink-soft)] hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]'"
 class="rounded-lg px-5 py-3.5 text-left text-base font-semibold transition"
 >
 Output
 </button>

 <button
 type="button"
 @click="activeWorkbenchTab = 'instructions'"
 :class="activeWorkbenchTab === 'instructions'
 ? 'border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-ink-strong)] border-t-2'
 : 'border border-[var(--color-line)] bg-white text-[var(--color-ink-soft)] hover:border-[var(--color-line-strong)] hover:text-[var(--color-ink-strong)]'"
 class="rounded-lg px-5 py-3.5 text-left text-base font-semibold transition"
 >
 Instructions &amp; Media
 </button>
</nav>
