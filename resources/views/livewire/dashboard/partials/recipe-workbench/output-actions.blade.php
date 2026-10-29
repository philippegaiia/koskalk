@if ($isGuestCalculator ?? false)
    <div :aria-busy="guestOutputPending" class="flex shrink-0 items-center justify-end gap-2 pb-2 @6xl/workbench:pb-0">
        <button type="button" @click="printGuestFormula()" :disabled="!guestCanPrint || guestOutputPending" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">
            <x-action-icon name="printer" />{{ __('formula_documents.actions.print_short') }}
        </button>
    </div>
@elseif (is_array($workbench['output'] ?? null) && ! ($isPublicCalculator ?? false))
    {{-- Print and export buttons follow the shared output-button pattern: see "Print and export buttons share one look" in .ai/rules/views.md. --}}
    <div x-show="['formula', 'instructions', 'costing', 'output'].includes(activeWorkbenchTab)" x-cloak :aria-busy="outputPending" class="flex shrink-0 items-center justify-end gap-2 pb-2 @6xl/workbench:pb-0">
        <div x-show="activeWorkbenchTab === 'formula'" x-cloak class="flex items-center gap-2">
            <button type="button" @click="printCurrentFormula({ printContext: 'formula' })" :disabled="outputPending" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">
                <x-action-icon name="printer" />{{ __('formula_documents.actions.print_short') }}
            </button>

            <div x-data="{ outputExportMenuOpen: false }" @click.outside="outputExportMenuOpen = false" class="relative">
                <button x-ref="exportMenuTrigger" type="button" aria-haspopup="menu" :aria-expanded="outputExportMenuOpen" @click="outputExportMenuOpen = !outputExportMenuOpen; if (outputExportMenuOpen) $nextTick(() => $refs.xlsxExport.focus())" :disabled="outputPending" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">
                    <x-action-icon name="download" />{{ __('formula_documents.actions.export_short') }}<x-action-icon name="chevron-down" />
                </button>

                <div x-show="outputExportMenuOpen" x-cloak role="menu" @keydown.escape.stop.prevent="outputExportMenuOpen = false; $refs.exportMenuTrigger.focus()" class="absolute right-0 top-full z-20 mt-2 min-w-48 rounded-xl border border-[var(--color-line)] bg-[var(--color-panel)] p-1 shadow-lg">
                    <button x-ref="xlsxExport" type="button" role="menuitem" @click="outputExportMenuOpen = false; exportCurrentFormula('xlsx')" @keydown.arrow-down.prevent="$refs.csvExport.focus()" @keydown.arrow-up.prevent="$refs.csvExport.focus()" class="block min-h-11 w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-panel-strong)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)]">{{ __('formula_documents.actions.export_xlsx_option') }}</button>
                    <button x-ref="csvExport" type="button" role="menuitem" @click="outputExportMenuOpen = false; exportCurrentFormula('csv')" @keydown.arrow-down.prevent="$refs.xlsxExport.focus()" @keydown.arrow-up.prevent="$refs.xlsxExport.focus()" class="block min-h-11 w-full rounded-lg px-3 py-2 text-left text-sm font-medium text-[var(--color-ink-strong)] hover:bg-[var(--color-panel-strong)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--color-accent)]">{{ __('formula_documents.actions.export_csv_option') }}</button>
                </div>
            </div>
        </div>

        <div x-show="activeWorkbenchTab === 'instructions'" x-cloak class="flex items-center gap-2">
            <button type="button" @click="printCurrentFormula({ printContext: 'instructions' })" :disabled="outputPending" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">
                <x-action-icon name="printer" />{{ __('formula_documents.actions.print_short') }}
            </button>
        </div>

        <div x-show="activeWorkbenchTab === 'costing' && hasLoadedCosting" x-cloak class="flex items-center gap-2">
            <button type="button" @click="printCurrentFormula({ printContext: 'costing' })" :disabled="outputPending" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">
                <x-action-icon name="printer" />{{ __('formula_documents.actions.print_short') }}
            </button>
            <button type="button" @click="exportCurrentCosting()" :disabled="outputPending" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">
                <x-action-icon name="download" />{{ __('formula_documents.actions.export_short') }}
            </button>
        </div>

        <div x-show="activeWorkbenchTab === 'output'" x-cloak class="flex items-center gap-2">
            <button type="button" @click="printCurrentFormula({ printContext: 'output' })" :disabled="outputPending" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">
                <x-action-icon name="printer" />{{ __('formula_documents.actions.print_short') }}
            </button>
            <button type="button" @click="exportCurrentFormula('xlsx', 'composition')" :disabled="outputPending" class="sk-btn sk-btn-outline min-h-11 gap-2 whitespace-nowrap">
                <x-action-icon name="download" />{{ __('formula_documents.actions.export_short') }}
            </button>
        </div>
    </div>
@endif
