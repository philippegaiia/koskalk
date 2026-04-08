<nav class="rounded-[2rem] border border-[var(--color-accent)] bg-[var(--color-accent)] p-2 shadow-sm">
    <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-4">
        <button
            type="button"
            @click="activeWorkbenchTab = 'formula'"
            :class="activeWorkbenchTab === 'formula'
                ? 'border-white bg-white text-[var(--color-ink-strong)] shadow-sm'
                : 'border-white/35 bg-white/8 text-white hover:border-white/60 hover:bg-white/14'"
            class="rounded-[1.4rem] border px-4 py-3 text-left transition"
        >
            <p class="text-sm font-semibold">Formula</p>
            <p class="mt-1 text-xs leading-5 opacity-80">Build oils, lye, additives, and the live ingredient list preview.</p>
        </button>

        <button
            type="button"
            @click="activeWorkbenchTab = 'costing'; ensureCostingLoaded()"
            :class="activeWorkbenchTab === 'costing'
                ? 'border-white bg-white text-[var(--color-ink-strong)] shadow-sm'
                : 'border-white/35 bg-white/8 text-white hover:border-white/60 hover:bg-white/14'"
            class="rounded-[1.4rem] border px-4 py-3 text-left transition"
        >
            <p class="text-sm font-semibold">Costing</p>
            <p class="mt-1 text-xs leading-5 opacity-80">Price ingredients per kilo, reuse packaging items, and see the batch economics without disturbing formulation.</p>
        </button>

        <button
            type="button"
            @click="activeWorkbenchTab = 'output'"
            :class="activeWorkbenchTab === 'output'
                ? 'border-white bg-white text-[var(--color-ink-strong)] shadow-sm'
                : 'border-white/35 bg-white/8 text-white hover:border-white/60 hover:bg-white/14'"
            class="rounded-[1.4rem] border px-4 py-3 text-left transition"
        >
            <p class="text-sm font-semibold">Output</p>
            <p class="mt-1 text-xs leading-5 opacity-80">Review the dry-soap basis using the cured bar assumption and declared allergens.</p>
        </button>

        <button
            type="button"
            @click="activeWorkbenchTab = 'instructions'"
            :class="activeWorkbenchTab === 'instructions'
                ? 'border-white bg-white text-[var(--color-ink-strong)] shadow-sm'
                : 'border-white/35 bg-white/8 text-white hover:border-white/60 hover:bg-white/14'"
            class="rounded-[1.4rem] border px-4 py-3 text-left transition"
        >
            <p class="text-sm font-semibold">Instructions &amp; Media</p>
            <p class="mt-1 text-xs leading-5 opacity-80">Keep the manufacturing notes, images, and publishable recipe content together.</p>
        </button>
    </div>
</nav>
