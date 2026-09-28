@if (is_array($workbench['output'] ?? null))
    <div x-show="['formula', 'instructions', 'costing'].includes(activeWorkbenchTab)" x-cloak :aria-busy="outputPending" class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--color-line)] py-3">
        <div class="min-w-0 flex-1" aria-live="polite" aria-atomic="true">
            <p x-show="outputPending" x-cloak class="text-sm text-[var(--color-ink-soft)]" x-text="outputConfig?.messages?.pending"></p>
            <p x-show="outputError" x-cloak role="alert" class="text-sm text-[var(--color-danger-strong)]" x-text="outputError"></p>
            <a x-show="outputFallbackUrl" x-cloak :href="outputFallbackUrl" target="_blank" rel="noopener" class="text-sm font-medium text-[var(--color-accent-strong)] underline underline-offset-2" x-text="outputFallbackLabel"></a>
        </div>

        <div x-show="activeWorkbenchTab === 'formula'" x-cloak class="ml-auto flex flex-wrap items-center justify-end gap-2">
            <button type="button" @click="printCurrentFormula({ printContext: 'formula' })" :disabled="outputPending" class="sk-btn sk-btn-outline min-h-11">{{ __('formula_documents.actions.print_sheet') }}</button>

            <div x-data="{ outputExportMenuOpen: false }" @click.outside="outputExportMenuOpen = false" class="relative">
                <button x-ref="exportMenuTrigger" type="button" aria-haspopup="menu" :aria-expanded="outputExportMenuOpen" @click="outputExportMenuOpen = !outputExportMenuOpen; if (outputExportMenuOpen) $nextTick(() => $refs.xlsxExport.focus())" :disabled="outputPending" class="sk-btn sk-btn-ghost min-h-11">
                    {{ __('formula_documents.actions.export_formula') }}
                    <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" class="size-4"><path fill-rule="evenodd" d="M5.22 7.47a.75.75 0 0 1 1.06 0L10 11.19l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 8.53a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                </button>

                <div x-show="outputExportMenuOpen" x-cloak role="menu" @keydown.escape.stop.prevent="outputExportMenuOpen = false; $refs.exportMenuTrigger.focus()" class="absolute right-0 top-full z-20 mt-2 min-w-48 rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] p-1 shadow-lg">
                    <button x-ref="xlsxExport" type="button" role="menuitem" @click="outputExportMenuOpen = false; exportCurrentFormula('xlsx')" @keydown.arrow-down.prevent="$refs.csvExport.focus()" @keydown.arrow-up.prevent="$refs.csvExport.focus()" class="block min-h-11 w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-panel-strong)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)]">{{ __('formula_documents.actions.export_xlsx_option') }}</button>
                    <button x-ref="csvExport" type="button" role="menuitem" @click="outputExportMenuOpen = false; exportCurrentFormula('csv')" @keydown.arrow-down.prevent="$refs.xlsxExport.focus()" @keydown.arrow-up.prevent="$refs.xlsxExport.focus()" class="block min-h-11 w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-panel-strong)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)]">{{ __('formula_documents.actions.export_csv_option') }}</button>
                </div>
            </div>
        </div>

        <div x-show="activeWorkbenchTab === 'instructions'" x-cloak class="ml-auto flex flex-wrap items-center justify-end gap-2">
            <button type="button" @click="printCurrentFormula({ printContext: 'instructions' })" :disabled="outputPending" class="sk-btn sk-btn-outline min-h-11">{{ __('formula_documents.actions.print_sheet') }}</button>
        </div>

        <div x-show="activeWorkbenchTab === 'costing' && hasLoadedCosting" x-cloak class="ml-auto flex flex-wrap items-center justify-end gap-2">
            <button type="button" @click="printCurrentFormula({ printContext: 'costing' })" :disabled="outputPending" class="sk-btn sk-btn-outline min-h-11">{{ __('formula_documents.actions.print_sheet') }}</button>
            <button type="button" @click="exportCurrentCosting()" :disabled="outputPending" class="sk-btn sk-btn-ghost min-h-11">{{ __('formula_documents.actions.export_costing_excel') }}</button>
        </div>
    </div>
@endif
