<?php

/**
 * Every print and export control uses the same outline button with a printer
 * or download icon and a visible label.
 *
 * @return array<string, array{0: string, 1: list<string>}>
 */
function outputActionViews(): array
{
    return [
        'print preview' => ['components/formula-document/print-options.blade.php', ['formula_documents.actions.print_sheet']],
        'workbench' => ['livewire/dashboard/partials/recipe-workbench/output-actions.blade.php', ['formula_documents.actions.print_sheet', 'formula_documents.actions.export_formula', 'formula_documents.actions.export_costing', 'formula_documents.actions.export_composition']],
        'saved formula sheet' => ['recipes/version.blade.php', ['formula_documents.actions.print_sheet', 'formula_documents.actions.export_formula']],
        'saved sheet labeling' => ['recipes/partials/version-sheet.blade.php', ['formula_documents.actions.export_composition']],
        'ingredients' => ['livewire/dashboard/ingredients-index.blade.php', ['ingredients.exports.ui.label']],
        'packaging' => ['livewire/dashboard/packaging-items-index.blade.php', ['packaging.exports.ui.label']],
        'inventory' => ['livewire/production-bench/inventory-index.blade.php', ['production_bench.output.ui.materials', 'production_bench.output.ui.stock', 'production_bench.output.ui.count']],
        'stock preparation' => ['livewire/production-bench/production/stock-preparation.blade.php', ['production_bench.picking.print']],
        'production detail' => ['livewire/production-bench/production/production-detail.blade.php', ['production_bench.run_print.']],
        'purchase order' => ['livewire/production-bench/purchasing/procurement-detail.blade.php', ['production_bench.procurement.print_pdf']],
    ];
}

it('styles every print and export control the same way', function (string $view, array $labelKeys): void {
    $source = file_get_contents(resource_path('views/'.$view));

    foreach ($labelKeys as $labelKey) {
        $labelPosition = strpos($source, "{{ __('".$labelKey);
        expect($labelPosition)->toBeInt();

        $tagStart = max(strrpos(substr($source, 0, $labelPosition), '<button'), strrpos(substr($source, 0, $labelPosition), '<a '));
        $control = substr($source, $tagStart, $labelPosition - $tagStart);
        $icon = str_contains($labelKey, 'print') || str_contains($labelKey, '.count') ? 'printer' : 'download';

        expect($control)->toContain('sk-btn sk-btn-outline min-h-11')
            ->toContain('<x-action-icon name="'.$icon.'" />')
            ->not->toContain('sr-only');
    }
})->with(outputActionViews());
