<?php

use App\Forms\Components\MediaAssetPicker;
use App\Forms\RichEditor\Plugins\MediaLibraryRichContentPlugin;
use App\Livewire\Dashboard\RecipeWorkbench;
use App\Models\ProductFamily;
use App\Models\Recipe;
use App\Models\User;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

it('starts soap users in lipids and builds the selector from canonical catalogue categories', function (): void {
    $componentSource = file_get_contents(resource_path('js/recipe-workbench/component.js'));
    $catalogSource = file_get_contents(resource_path('js/recipe-workbench/catalog.js'));
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();

    expect($componentSource)
        ->toContain("activeCategory: isCosmeticFormula ? 'all' : 'lipids'")
        ->toContain('buildCategoryOptions(this.ingredients')
        ->not->toContain('CATEGORY_OPTIONS')
        ->not->toContain("'carrier_oil'")
        ->and($catalogSource)
        ->toContain('export function categoryOptions')
        ->toContain('ingredient.category_label')
        ->toContain('ingredient.subcategory_label')
        ->not->toContain("value: 'carrier_oil'")
        ->and($ingredientBrowser)
        ->toContain('data-search-combobox="ingredient-category-search"')
        ->toContain('x-init="replaceOptions(categoryOptions.map')
        ->toContain('syncSelection(activeCategory)')
        ->toContain('x-on:search-combobox-selected="activeCategory = String($event.detail.id)"')
        ->toContain('x-on:search-combobox-cleared="activeCategory = \'all\'; syncSelection(\'all\')"');
});

it('keeps formula-start compliance controls available but collapsed by default', function () {
    $componentSource = file_get_contents(resource_path('js/recipe-workbench/component.js'));
    $soapSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings')->render();
    $cosmeticSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings', [
        'isCosmeticWorkbench' => true,
    ])->render();

    expect($componentSource)
        ->toContain('isComplianceSettingsOpen: false')
        ->and($soapSettings)
        ->toContain('Label &amp; compliance')
        ->toContain(":class=\"isComplianceSettingsOpen ? 'grid-rows-[1fr]' : 'grid-rows-[0fr] invisible'\"")
        ->toContain('Suggested from product type')
        ->toContain('Choose another IFRA category')
        ->toContain('No IFRA category')
        ->toContain('Use suggested category')
        ->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('x-trap.inert.noscroll="isIfraCategoryModalOpen"')
        ->toContain('Optional guidance. If you market one product for several uses, review every applicable IFRA category; Koskalk does not choose a universal “strictest” category.')
        ->toContain('IFRA amendment timing')
        ->toContain('These dates apply to fragrance mixtures leaving a fragrance house.')
        ->and($cosmeticSettings)
        ->toContain('Label &amp; compliance')
        ->toContain(":class=\"isComplianceSettingsOpen ? 'grid-rows-[1fr]' : 'grid-rows-[0fr] invisible'\"")
        ->toContain('Suggested from product type')
        ->toContain('x-show="productTypes.length && ! hasSavedFormula"')
        ->toContain('x-show="hasSavedFormula"')
        ->toContain('Product type is fixed after the first Saved Formula.');

    expect(strpos($soapSettings, 'setting-exposure-soap'))
        ->toBeLessThan(strpos($soapSettings, 'Label &amp; compliance'));
});

it('enforces the formula line limit without showing a line counter', function (): void {
    $componentSource = file_get_contents(resource_path('js/recipe-workbench/component.js'));
    $formulaSectionSource = file_get_contents(resource_path('js/recipe-workbench/sections/formula-section.js'));
    $soapFormulaTab = view('livewire.dashboard.partials.recipe-workbench.formula-tab')->render();
    $cosmeticFormulaTab = view('livewire.dashboard.partials.recipe-workbench.formula-tab', [
        'isCosmeticWorkbench' => true,
    ])->render();
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();

    expect($componentSource)
        ->toContain('formulaItemCount()')
        ->toContain('formulaItemLimitReached()')
        ->and($formulaSectionSource)
        ->toContain("this.formulaItemLimitMessage = '';")
        ->and($soapFormulaTab)
        ->not->toContain('formula_items.limited_count')
        ->not->toContain('formula_items.limit_reached')
        ->and($cosmeticFormulaTab)
        ->not->toContain('formula_items.limited_count')
        ->not->toContain('formula_items.limit_reached')
        ->and($ingredientBrowser)
        ->toContain(':disabled="formulaItemLimitReached()"')
        ->toContain(':aria-disabled="formulaItemLimitReached().toString()"');
});

it('presents the workbench header as a quiet hierarchy with compact section navigation', function () {
    $savedFormulaUrl = 'http://koskalk.test/dashboard/recipes/savon-de-marseille/saved';
    $workbench = [
        'recipe' => [
            'public_id' => 'recipe-test',
            'has_saved_formula' => true,
            'is_locked' => false,
            'saved_formula_url' => $savedFormulaUrl,
        ],
    ];
    $header = view('livewire.dashboard.partials.recipe-workbench.header', compact('workbench'))->render();
    $navigation = view('livewire.dashboard.partials.recipe-workbench.navigation', compact('workbench'))->render();
    $publicNavigation = view('livewire.dashboard.partials.recipe-workbench.navigation', [
        'isPublicCalculator' => true,
    ])->render();
    $appStylesSource = file_get_contents(resource_path('css/app.css'));
    $workbenchSource = file_get_contents(resource_path('views/livewire/dashboard/recipe-workbench.blade.php'));
    $bottomActionBarSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-bottom-action-bar.blade.php'));
    $recipeWorkbenchPageSource = file_get_contents(resource_path('views/recipes/workbench.blade.php'));
    $appShellSource = file_get_contents(resource_path('views/layouts/app-shell.blade.php'));

    preg_match(
        '/\\.sk-workbench \\.sk-workbench-tabs \\{(?<rule>.*?)\\n\\}/s',
        $appStylesSource,
        $tabTrackMatches,
    );
    preg_match(
        '/<nav[^>]*>.*sk-formula-sheet-link.*<\\/nav>/s',
        $navigation,
        $formulaSheetInsideNavigation,
    );

    expect($navigation)
        ->not->toContain('border-t-2')
        ->toContain('overflow-x-auto')
        ->not->toContain('min-w-max')
        ->toContain('relative min-w-0 max-w-full overflow-hidden')
        ->toContain('min-w-0 max-w-full')
        ->toContain('touch-pan-x')
        ->toContain('data-tab-overflow-cue')
        ->toContain('sk-workbench-tabs')
        ->toContain('sk-workbench-tab')
        ->toContain('text-base')
        ->not->toContain('xl:text-lg')
        ->toContain('sk-formula-sheet-link')
        ->toContain($savedFormulaUrl)
        ->not->toContain('role="tab" href=')
        ->not->toContain('ring-1')
        ->and($formulaSheetInsideNavigation)
        ->not->toBeEmpty()
        ->and($publicNavigation)
        ->toContain('grid gap-2 sm:grid-cols-2')
        ->not->toContain('overflow-x-auto')
        ->and($appStylesSource)
        ->toContain('.sk-workbench .sk-workbench-tabs')
        ->toContain('.sk-workbench .sk-workbench-tab')
        ->toContain('.sk-workbench .sk-workbench-tab.is-active')
        ->toContain('.sk-workbench .sk-workbench-tab.is-active::before')
        ->toContain('background-color: transparent')
        ->toContain('color: var(--color-accent-strong) !important')
        ->toContain('min-height: 3.25rem')
        ->not->toContain('background-color: var(--color-active-strong)')
        ->and($tabTrackMatches['rule'] ?? null)
        ->toContain('padding: 0')
        ->toContain('background-color: transparent')
        ->and($workbenchSource)
        ->toContain('@container/workbench')
        ->toContain('mx-auto max-w-app')
        ->not->toContain('max-w-[90rem]')
        ->not->toContain('max-w-[104rem]')
        ->and($bottomActionBarSource)
        ->toContain('mx-auto max-w-app')
        ->not->toContain('max-w-[90rem]')
        ->not->toContain('max-w-[104rem]')
        ->and($recipeWorkbenchPageSource)
        ->not->toContain('$productType->localizedName()')
        ->not->toContain('mx-auto mb-4 max-w-app')
        ->toContain("@section('page_heading', 'Recipe workbench')")
        ->not->toContain('max-w-[90rem]')
        ->not->toContain('max-w-[104rem]')
        ->and($appShellSource)
        ->toContain('class="relative mx-auto min-h-dvh w-full max-w-[2100px]')
        ->not->toContain('max-w-[120rem]')
        ->and($header)
        ->toContain('Products')
        ->toContain('formulaWorkbenchLabel')
        ->toContain('sk-formula-header')
        ->not->toContain('sk-card p-5')
        ->toContain('mt-3 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between')
        ->toContain('sk-formula-title-control min-w-0 flex-1')
        ->toContain('sk-formula-actions')
        ->toContain('<span x-show="productTypeName" class="sk-badge sk-badge-neutral" x-text="productTypeName"></span>')
        ->not->toContain('IFRA')
        ->toContain('flex shrink-0 flex-wrap items-center gap-2')
        ->not->toContain('lg:grid-cols-[minmax(0,1fr)_auto]')
        ->not->toContain('lg:contents')
        ->not->toContain('lg:row-start-')
        ->toContain('Lock product')
        ->and(strpos($header, 'Lock product'))
        ->toBeLessThan(strpos($header, 'More actions'))
        ->and(substr_count($header, 'Lock product'))
        ->toBe(1)
        ->and($header)
        ->toContain('More actions')
        ->toContain('<details')
        ->not->toContain('Formula sheet')
        ->not->toContain('Open reference formula</a>')
        ->not->toContain('Save as reference formula');
});

it('uses deliberate spacing between workbench breadcrumbs, title, actions, and navigation', function () {
    $header = view('livewire.dashboard.partials.recipe-workbench.header')->render();
    $workbenchSource = file_get_contents(resource_path('views/livewire/dashboard/recipe-workbench.blade.php'));

    expect($header)
        ->toContain('class="mt-3 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"')
        ->toContain('x-show="productTypeName || saveMessage || calculationPreviewMessage"')
        ->toContain('class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs')
        ->not->toContain('min-h-6')
        ->not->toContain('lg:row-start-')
        ->and($workbenchSource)
        ->toContain('<div class="space-y-4">')
        ->not->toContain('<div class="space-y-2">');
});

it('hardens compact workbench controls for touch and keyboard use', function () {
    $partials = [
        view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render(),
        view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render(),
        view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render(),
        view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render(),
        view('livewire.dashboard.partials.recipe-workbench.packaging-tab')->render(),
    ];

    $combinedWorkbenchMarkup = implode("\n", $partials);
    $header = view('livewire.dashboard.partials.recipe-workbench.header')->render();

    expect($combinedWorkbenchMarkup)
        ->not->toContain('size-6')
        ->not->toContain('size-7')
        ->not->toContain('size-8')
        ->toContain('class="grid size-9 place-items-center rounded-full', false)
        ->toContain('aria-haspopup="dialog"')
        ->toContain(':aria-expanded="open.toString()"')
        ->toContain('@keydown.escape.window="open = false"');

    expect($header)
        ->toContain('@click.outside="open = false"')
        ->toContain('@keydown.escape.prevent.stop="open = false"');
});

it('uses quiet actionable formula balance readouts and flat formula ledgers', function () {
    $cosmeticFormula = view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render();
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $formulaSectionSource = file_get_contents(resource_path('js/recipe-workbench/sections/formula-section.js'));
    $translationSource = file_get_contents(lang_path('en/workbench.php'));

    expect($cosmeticFormula)
        ->toContain('data-formula-balance-status')
        ->toContain("oilPercentageIsBalanced ? 'text-[var(--color-success-strong)]' : 'text-[var(--color-warning-strong)]'")
        ->toContain('class="inline-flex items-baseline gap-2 text-sm font-medium transition-colors"')
        ->toContain('class="numeric font-semibold" x-text="`${formatPercentageTotal(totalOilPercentage())}%`"')
        ->toContain('<span x-text="oilPercentageStatusLabel"></span>')
        ->not->toContain('rounded-full border px-4 py-2')
        ->not->toContain('numeric rounded-full bg-white')
        ->not->toContain(':data-cosmetic-phase-key="phase.key" class="overflow-hidden transition-shadow duration-300 sk-inset"')
        ->not->toContain('<div class="overflow-hidden sk-inset">')
        ->and($reactionCore)
        ->toContain('data-formula-balance-status')
        ->toContain("oilPercentageIsBalanced ? 'text-[var(--color-success-strong)]' : 'text-[var(--color-danger-strong)]'")
        ->toContain('class="inline-flex items-baseline gap-2 text-sm font-medium transition-colors"')
        ->toContain('class="numeric font-semibold" x-text="`${formatPercentageTotal(totalOilPercentage())}%`"')
        ->toContain('<span x-text="oilPercentageStatusLabel"></span>')
        ->not->toContain('rounded-full border px-4 py-2')
        ->not->toContain('numeric rounded-full bg-white')
        ->not->toContain('<div class="relative sk-inset">')
        ->and($formulaSectionSource)
        ->toContain('get oilPercentageIsBalanced()')
        ->toContain("return this.t('status.balanced');")
        ->toContain('return total < 100')
        ->toContain("? this.t('status.add_percentage', { amount })")
        ->toContain(": this.t('status.remove_percentage', { amount });")
        ->and($translationSource)
        ->toContain("'add_percentage' => 'Add :amount%'")
        ->toContain("'remove_percentage' => 'Remove :amount%'")
        ->toContain("'balanced' => 'Balanced'");
});

it('presents batch totals as one compact neutral summary grid', function () {
    $postReaction = view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render();
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $appStylesSource = file_get_contents(resource_path('css/app.css'));
    $sharedStylesSource = file_get_contents(resource_path('css/shared/soapkraft.css'));

    expect($postReaction)
        ->toContain('sk-phase-craft sk-tone-summary')
        ->not->toContain('sk-phase-craft sk-tone-materials')
        ->toContain('sk-card sk-tone-summary overflow-hidden')
        ->toContain('sk-section-header sk-section-header-formula border-b px-5 py-4')
        ->toContain('grid gap-px bg-[var(--color-line)] sm:grid-cols-2 xl:grid-cols-4')
        ->toContain('flex flex-col bg-[var(--color-panel)] px-4 py-3')
        ->toContain('sk-eyebrow min-h-8')
        ->toContain('numeric mt-1.5 text-xl')
        ->not->toContain('numeric mt-3')
        ->not->toContain('min-h-24')
        ->not->toContain('A quick read of the current formula outputs')
        ->not->toContain('sk-inset flex h-full flex-col justify-between p-4')
        ->not->toContain('numeric pt-6')
        ->and($reactionCore)
        ->toContain('numeric mt-2 whitespace-nowrap text-xl')
        ->not->toContain('flex-col justify-between px-3 py-2.5')
        ->and($appStylesSource)
        ->toContain('.sk-phase-craft .sk-section-header')
        ->toContain('background: color-mix(in oklab, var(--color-panel-strong) 52%, var(--color-panel) 48%)')
        ->and($sharedStylesSource)
        ->toContain('.sk-tone-summary')
        ->toContain('--sk-tone-soft: var(--color-panel-strong)')
        ->toContain('--sk-tone-strong: var(--color-ink)');
});

it('keeps water mode controls outlined and compact', function () {
    $formulaSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings')->render();

    expect(substr_count($formulaSettings, 'rounded-[1rem] border px-4 py-2.5 text-left text-xs font-medium transition-colors'))
        ->toBe(3)
        ->and($formulaSettings)
        ->toContain('border-[var(--color-active)] bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm')
        ->toContain('border-[var(--color-field-outline)] bg-transparent text-[var(--color-ink-strong)] hover:border-[var(--color-line-strong)] hover:bg-[var(--color-field-muted)]')
        ->toContain('@5xl/workbench:grid-cols-4')
        ->not->toContain('@6xl/workbench:grid-cols-[repeat(5,minmax(12rem,1fr))]')
        ->not->toContain('@7xl/workbench:grid-cols-[repeat(5,minmax(12rem,1fr))]');
});

it('keeps soap setup controls in original core and contextual cards', function (): void {
    $soapSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings')->render();
    $publicSoapSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings', [
        'isPublicCalculator' => true,
    ])->render();
    $deferredOutput = view('livewire.dashboard.partials.recipe-workbench.formula-output-type', [
        'deferFormulaOutputIngredientFields' => true,
    ])->render();
    $defaultOutput = view('livewire.dashboard.partials.recipe-workbench.formula-output-type')->render();

    $coreGridPosition = strpos($soapSettings, 'grid min-w-0 gap-4 @3xl/workbench:grid-cols-2 @4xl/workbench:grid-cols-3 @5xl/workbench:grid-cols-4');
    $contextGridPosition = strpos($soapSettings, 'mt-4 grid min-w-0 gap-4 @3xl/workbench:grid-cols-2 @4xl/workbench:grid-cols-3');
    $lyeTypePosition = strpos($soapSettings, 'id="setting-lye-type"');
    $oilWeightPosition = strpos($soapSettings, 'id="setting-base-weight"');
    $waterModePosition = strpos($soapSettings, 'id="setting-water-mode"');
    $superfatPosition = strpos($soapSettings, 'id="setting-superfat"');
    $outputPosition = strpos($soapSettings, 'data-formula-output-type');
    $productUsePosition = strpos($soapSettings, 'id="setting-exposure-soap"');
    $compliancePosition = strpos($soapSettings, 'Label &amp; compliance');
    $fieldsWrapperPosition = strpos($soapSettings, 'x-show="productionOutputType === \'manufactured_ingredient\'" x-cloak');
    $fieldsPosition = strpos($soapSettings, 'data-formula-output-ingredient-fields');
    $editorPosition = strpos($soapSettings, 'id="setting-regime-soap"');
    $publicContextGridPosition = strpos($publicSoapSettings, 'mt-4 grid min-w-0 gap-4 @3xl/workbench:grid-cols-2');
    $publicContextGrid = substr($publicSoapSettings, $publicContextGridPosition, strpos($publicSoapSettings, '>', $publicContextGridPosition) - $publicContextGridPosition + 1);
    $contextCards = substr($soapSettings, $contextGridPosition, $fieldsWrapperPosition - $contextGridPosition);
    $publicEditorPosition = strpos($publicSoapSettings, 'id="setting-regime-soap"');
    $publicContextCards = substr($publicSoapSettings, $publicContextGridPosition, $publicEditorPosition - $publicContextGridPosition);

    expect($soapSettings)
        ->toContain('grid min-w-0 gap-4 @3xl/workbench:grid-cols-2 @4xl/workbench:grid-cols-3 @5xl/workbench:grid-cols-4')
        ->toContain('mt-4 grid min-w-0 gap-4 @3xl/workbench:grid-cols-2 @4xl/workbench:grid-cols-3')
        ->toContain('data-formula-output-type')
        ->toContain('id="setting-exposure-soap"')
        ->toContain('Label &amp; compliance')
        ->toContain('x-show="productionOutputType === \'manufactured_ingredient\'" x-cloak class="mt-4 sk-inset sk-tone-info p-4"')
        ->toContain('id="setting-regime-soap"')
        ->not->toContain('data-soap-formula-settings')
        ->not->toContain('compactSoapOutputType')
        ->not->toContain('<select aria-labelledby="setting-water-mode"')
        ->and(substr_count($soapSettings, 'data-formula-output-ingredient-fields'))
        ->toBe(1)
        ->and($coreGridPosition)
        ->toBeLessThan($contextGridPosition)
        ->and($lyeTypePosition)
        ->toBeLessThan($oilWeightPosition)
        ->and($oilWeightPosition)
        ->toBeLessThan($waterModePosition)
        ->and($waterModePosition)
        ->toBeLessThan($superfatPosition)
        ->and($superfatPosition)
        ->toBeLessThan($contextGridPosition)
        ->and(substr_count($contextCards, 'sk-inset sk-tone-info'))
        ->toBe(3)
        ->and($outputPosition)
        ->toBeLessThan($productUsePosition)
        ->and($productUsePosition)
        ->toBeLessThan($compliancePosition)
        ->and($compliancePosition)
        ->toBeLessThan($fieldsPosition)
        ->and($fieldsPosition)
        ->toBeLessThan($editorPosition)
        ->and($publicSoapSettings)
        ->not->toContain('data-formula-output-type')
        ->not->toContain('data-formula-output-ingredient-fields')
        ->and($publicContextGrid)
        ->toContain('@3xl/workbench:grid-cols-2')
        ->not->toContain('@4xl/workbench:grid-cols-3')
        ->and(substr_count($publicContextCards, 'sk-inset sk-tone-info'))
        ->toBe(2)
        ->and(substr_count($publicSoapSettings, 'class="sk-inset sk-tone-info min-w-0 p-4"'))
        ->toBe(2)
        ->and($deferredOutput)
        ->toContain('class="sk-inset sk-tone-info p-4" data-formula-output-type')
        ->toContain('rounded-full border')
        ->not->toContain('mb-4')
        ->not->toContain('data-formula-output-ingredient-fields')
        ->and($defaultOutput)
        ->toContain('class="sk-inset sk-tone-info p-4 mb-4" data-formula-output-type')
        ->toContain('data-formula-output-ingredient-fields');
});

it('aligns dilution liquid headings with their responsive rows', function (): void {
    $formulaSettings = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php'));

    expect($formulaSettings)
        ->toContain('hidden grid-cols-[minmax(0,1fr)_10rem_10rem_2.5rem] gap-3 bg-[var(--color-field-muted)] text-xs font-medium text-[var(--color-ink-soft)] sm:grid sm:items-center sm:px-3')
        ->toContain('<div class="bg-[var(--color-field-muted)] py-2">{{ __(\'workbench.common.ingredient\') }}</div>')
        ->toContain('<div class="bg-[var(--color-field-muted)] px-3 py-2">{{ __(\'workbench.settings.lye_liquid_percentage\') }}</div>')
        ->toContain('<div class="bg-[var(--color-field-muted)] py-2" x-text="t(\'settings.lye_liquid_fresh_weight\', { unit: oilUnit })"></div>')
        ->not->toContain('grid-cols-[minmax(0,1fr)_10rem_10rem_2.5rem] gap-px')
        ->and($formulaSettings)
        ->toContain('class="grid gap-3 px-3 py-3 sm:grid-cols-[minmax(0,1fr)_10rem_10rem_2.5rem] sm:items-center"')
        ->toContain('lyeLiquidAdditionLimitReached()')
        ->toContain('x-effect="syncFormattedInput($el, row.percentage, 2)" :style="decimalAlignmentStyle(row.percentage)"')
        ->toContain(':style="decimalAlignmentStyle(lyeLiquidWeight(row))"')
        ->toContain('rounded-full border border-[var(--color-field-outline)] bg-transparent px-3 py-2 text-xs')
        ->toContain('sm:hidden');
});

it('uses one focus boundary on the superfat input', function () {
    $formulaSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings')->render();
    $appStylesSource = file_get_contents(resource_path('css/app.css'));

    expect($formulaSettings)
        ->toContain('sk-superfat-control')
        ->and($appStylesSource)
        ->toContain('.sk-workbench input.sk-superfat-control:focus-visible')
        ->toContain('box-shadow: none')
        ->toContain('border-color: var(--color-accent)');
});

it('keeps soap qualities compact and presents comments as discreet formula notes', function () {
    $formulaAnalysis = view('livewire.dashboard.partials.recipe-workbench.formula-analysis')->render();
    $appStylesSource = file_get_contents(resource_path('css/app.css'));

    expect($formulaAnalysis)
        ->toContain('soapQualitiesExpanded: true')
        ->toContain(":class=\"soapQualitiesExpanded ? 'grid-rows-[1fr]' : 'grid-rows-[0fr] invisible'\"")
        ->toContain('transition-[grid-template-rows,visibility] duration-300 ease-out motion-reduce:transition-none')
        ->toContain(':aria-expanded="soapQualitiesExpanded.toString()"', false)
        ->toContain('Bar &amp; cure', false)
        ->toContain('Lather &amp; feel', false)
        ->toContain('inline-flex items-center gap-2')
        ->toContain('rounded-lg border border-b-2 border-[var(--color-line)] bg-[var(--color-panel)]/35 px-3.5 py-2')
        ->toContain("'border-b-[var(--color-ink-strong)] text-[var(--color-ink-strong)]'")
        ->not->toContain("'border-b-[var(--color-accent)] text-[var(--color-accent)]'")
        ->toContain('class="sk-quality-disclosure grid size-9 shrink-0 place-items-center rounded-full border', false)
        ->not->toContain('gap-6 border-b border-[var(--color-line)]')
        ->not->toContain('rounded-[1.15rem] border border-[var(--color-line)] bg-[var(--color-field)] p-1')
        ->not->toContain('<span x-text="soapQualitiesExpanded ? \'Hide\' : \'Show\'"></span>', false)
        ->toContain('sk-eyebrow block min-h-8')
        ->toContain('numeric mt-1.5 text-xl')
        ->not->toContain('numeric mt-2 text-2xl')
        ->not->toContain('block text-sm font-medium leading-5')
        ->toContain('aria-label="Points to review"')
        ->toContain('divide-y divide-[var(--color-line)]')
        ->toContain('sm:grid-cols-[10rem_minmax(0,1fr)]')
        ->not->toContain('rounded-lg border border-[var(--color-line-strong)] bg-[var(--color-accent-soft)] px-3 py-2')
        ->and($appStylesSource)
        ->toContain('.sk-quality-disclosure:focus-visible')
        ->toContain('border-radius: 9999px');
});

it('adapts recipe workbench tables for narrow screens before desktop grids', function () {
    $tablePartials = [
        view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render(),
        view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render(),
        view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render(),
        view('livewire.dashboard.partials.recipe-workbench.packaging-tab')->render(),
    ];

    $combinedTableMarkup = implode("\n", $tablePartials);

    expect($combinedTableMarkup)
        ->toContain('grid-cols-1')
        ->toContain('lg:grid-cols-[2.75rem_minmax(0,1.8fr)_8.5rem_8.5rem_2.5rem]')
        ->toContain('lg:hidden')
        ->toContain('lg:grid')
        ->toContain('touch-pan-x')
        ->not->toContain('min-w-[58rem]');
});

it('keeps soap percentage and weight controls side by side below desktop', function () {
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $postReaction = view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render();
    $reactionCoreSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php'));
    $postReactionSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/post-reaction.blade.php'));
    $mobileMeasurementGroup = 'grid grid-cols-2 gap-3 lg:contents';
    $mobileHandlePlacement = 'col-start-1 row-start-1';
    $mobileIdentityPlacement = 'col-span-2 row-start-2';
    $mobileRemovalPlacement = 'col-start-2 row-start-1';
    $mobileLabel = 'sk-eyebrow text-center lg:hidden';

    expect(substr_count($reactionCore, $mobileMeasurementGroup))->toBe(2)
        ->and(substr_count($postReaction, $mobileMeasurementGroup))->toBe(2)
        ->and($reactionCore)
        ->toContain('lg:grid-cols-[2.75rem_minmax(0,1.8fr)_8.5rem_8.5rem_2.5rem]')
        ->toContain($mobileHandlePlacement)
        ->toContain($mobileIdentityPlacement)
        ->toContain($mobileRemovalPlacement)
        ->toContain($mobileLabel)
        ->toContain('lg:col-start-1')
        ->toContain('lg:col-start-2')
        ->toContain('lg:col-start-5')
        ->toContain('decimalAlignmentStyle(row.percentage)')
        ->toContain('decimalAlignmentStyle(rowWeight(row))')
        ->toContain('@dragstart="beginRowDrag(\'saponified_oils\', row.id, $event)"')
        ->and($postReaction)
        ->toContain($mobileHandlePlacement)
        ->toContain($mobileIdentityPlacement)
        ->toContain($mobileRemovalPlacement)
        ->toContain($mobileLabel)
        ->toContain('lg:col-start-1')
        ->toContain('lg:col-start-2')
        ->toContain('lg:col-start-5')
        ->toContain('decimalAlignmentStyle(row.percentage)')
        ->toContain('decimalAlignmentStyle(rowWeight(row))')
        ->toContain('@dragstart="beginRowDrag(\'additives\', row.id, $event)"')
        ->toContain('@dragstart="beginRowDrag(\'fragrance\', row.id, $event)"')
        ->and($reactionCoreSource)
        ->toContain('<x-recipe-workbench.formula-row-actions phase-key="saponified_oils" />')
        ->and($postReactionSource)
        ->toContain('<x-recipe-workbench.formula-row-actions phase-key="additives" />')
        ->toContain('<x-recipe-workbench.formula-row-actions phase-key="fragrance" />');
});

it('matches the soap mobile ledger hierarchy for cosmetic rows', function (): void {
    $cosmeticFormula = view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render();

    expect($cosmeticFormula)
        ->toContain('class="grid grid-cols-2 gap-3')
        ->toContain('col-start-1 row-start-1')
        ->toContain('col-span-2 row-start-2')
        ->toContain('col-span-full row-start-3 grid grid-cols-2 gap-3 lg:contents')
        ->toContain('col-start-2 row-start-1')
        ->toContain('lg:grid-cols-[2.75rem_minmax(0,1.8fr)_8.5rem_8.5rem_2.5rem]')
        ->not->toContain('class="grid grid-cols-1 gap-3 bg-[var(--color-panel)] px-2.5 py-2.5 text-sm sk-formula-table-row');
});

it('uses one accessible row-actions menu in every formula ledger', function (): void {
    $reactionCoreSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php'));
    $postReactionSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/post-reaction.blade.php'));
    $cosmeticFormulaSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/cosmetic-formula.blade.php'));
    $rowActionsPath = resource_path('views/components/recipe-workbench/formula-row-actions.blade.php');
    $rowActionsSource = file_exists($rowActionsPath)
        ? file_get_contents($rowActionsPath)
        : '';
    $rowTemplateSources = implode("\n", [$reactionCoreSource, $postReactionSource, $cosmeticFormulaSource]);
    preg_match('/<button\b(?=[^>]*\bx-ref="trigger")[^>]*\bclass="([^"]+)"[^>]*>/s', $rowActionsSource, $triggerMatches);
    $rowActionsTriggerClass = $triggerMatches[1] ?? '';

    expect(substr_count($reactionCoreSource, '<x-recipe-workbench.formula-row-actions'))
        ->toBe(1)
        ->and($reactionCoreSource)
        ->toContain('phase-key="saponified_oils"')
        ->and(substr_count($postReactionSource, '<x-recipe-workbench.formula-row-actions'))
        ->toBe(2)
        ->and($postReactionSource)
        ->toContain('phase-key="additives"')
        ->toContain('phase-key="fragrance"')
        ->and(substr_count($cosmeticFormulaSource, '<x-recipe-workbench.formula-row-actions'))
        ->toBe(1)
        ->and($cosmeticFormulaSource)
        ->toContain('phase-key-expression="phase.key"')
        ->and($rowActionsPath)
        ->toBeFile()
        ->and($rowActionsSource)
        ->toContain('aria-haspopup="menu"')
        ->toContain('$phaseKeyExpression')
        ->toContain('json_encode')
        ->toMatch('/(?:x-bind:aria-label|:aria-label)="[^"]*row\\.name[^"]*"/')
        ->toMatch('/x-ref="[^\"]*trigger/')
        ->toContain('moveFormulaRowBy')
        ->toContain('moveFormulaRowToPhase')
        ->toContain('formulaRowMoveTargets')
        ->toMatch('/(?:Move up|move_up)/')
        ->toMatch('/(?:Move down|move_down)/')
        ->toMatch('/(?:Remove ingredient|remove_ingredient|row_actions\\.remove)/')
        ->toMatch('/x-for="[^\"]*(?:phase|Phase)[^\"]*"/')
        ->toMatch('/[Pp]hase\\.key/')
        ->not->toMatch('/x-for="[^\"]*\\bin\\s+phaseOrder/')
        ->toContain("\$dispatch('formula-row-actions-opened', { rowId: row.id })")
        ->toContain('@formula-row-actions-opened.window="if (open && $event.detail.rowId !== row.id) { closeMenu(false); }"')
        ->toContain('closeMenu(shouldRestoreFocus = true)')
        ->toContain('@click.outside="closeMenu(false)"')
        ->toContain('focus()')
        ->and($rowActionsTriggerClass)
        ->toContain('min-h-11')
        ->toContain('min-w-11')
        ->toContain('border-0')
        ->toContain('bg-transparent')
        ->toContain('text-[var(--color-ink)]')
        ->toContain('hover:text-[var(--color-ink-strong)]')
        ->not->toContain('text-[var(--color-ink-soft)]')
        ->not->toContain('hover:bg-[var(--color-field-muted)]')
        ->not->toContain('rounded-lg')
        ->not->toContain('border-[var(--color-line)]')
        ->not->toContain('bg-[var(--color-field)]')
        ->and(substr_count($reactionCoreSource, '<x-action-icon name="drag" />'))
        ->toBe(1)
        ->and(substr_count($postReactionSource, '<x-action-icon name="drag" />'))
        ->toBe(2)
        ->and(substr_count($cosmeticFormulaSource, '<x-action-icon name="drag" />'))
        ->toBe(1)
        ->and($rowTemplateSources)
        ->not->toMatch('/<button\b[^>]*(?:moveFormulaRow(?:By|ToPhase)|row_actions\.move_(?:up|down)|Move\s+(?:up|down))[^>]*>/i');

    expect(substr_count($rowActionsSource, 'row_actions.move_up'))->toBe(1);
    expect(substr_count($rowActionsSource, 'row_actions.move_down'))->toBe(1);
});

it('keeps teleported row-action menu selectors valid inside HTML attributes', function (): void {
    $rowActionsSource = file_get_contents(resource_path('views/components/recipe-workbench/formula-row-actions.blade.php'));

    expect($rowActionsSource)
        ->toContain("querySelector('[role=menuitem]:not([disabled])')")
        ->not->toContain('[role=\\"menuitem\\"]');
});

it('constrains long formula row menus to the dynamic viewport', function (): void {
    $rowActionsSource = file_get_contents(resource_path('views/components/recipe-workbench/formula-row-actions.blade.php'));
    preg_match('/<div\\s+x-show="open"[\\s\\S]*?:style="panelStyle"[\\s\\S]*?class="([^"]+)"/', $rowActionsSource, $menuMatches);

    expect($menuMatches[1] ?? '')
        ->toContain('fixed')
        ->toContain('max-h-[min(24rem,calc(100dvh-2rem))]')
        ->toContain('overflow-y-auto')
        ->toContain('overscroll-contain');
});

it('renders one accessible phase confirmation dialog and formula removal undo status', function (): void {
    $formulaTabSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php'));
    $confirmationModalPath = resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-confirmation-modal.blade.php');
    $confirmationModalSource = file_exists($confirmationModalPath)
        ? file_get_contents($confirmationModalPath)
        : '';
    $bottomActionBarSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-bottom-action-bar.blade.php'));

    expect(substr_count($formulaTabSource, "@include('livewire.dashboard.partials.recipe-workbench.formula-confirmation-modal')"))
        ->toBe(1)
        ->and($confirmationModalPath)
        ->toBeFile()
        ->and($confirmationModalSource)
        ->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('aria-labelledby=')
        ->toContain('aria-describedby=')
        ->toContain('x-trap.inert.noscroll')
        ->toMatch('/(?:@|x-on:)keydown\\.escape/')
        ->toMatch('/(?:@|x-on:)click\\.self/')
        ->toContain('cancelCosmeticPhaseRemoval')
        ->toContain('confirmCosmeticPhaseRemoval')
        ->toMatch('/x-ref="[^\"]*cancel[^\"]*"/i')
        ->toMatch('/x-init="[^\"]*\\$refs\\.[^\"]*cancel[^\"]*focus\\(\)/i')
        ->toMatch('/(?:Remove phase|remove_phase|phase_removal\\.confirm)/')
        ->toContain('rowCount')
        ->toContain('pendingCosmeticPhaseRemoval')
        ->toMatch('/(?:danger|color-danger)/')
        ->and($bottomActionBarSource)
        ->toContain('removedFormulaRowUndo')
        ->toContain('role="status"')
        ->toContain('undoFormulaRowRemoval')
        ->toMatch('/(?:Undo|undo)/');
});

it('renders formula row removal undo as a compact dismissible status', function (): void {
    $bottomActionBarSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-bottom-action-bar.blade.php'));
    preg_match('/<div\b(?=[^>]*x-show="removedFormulaRowUndo")(?=[^>]*class="([^"]+)")[^>]*>/s', $bottomActionBarSource, $statusMatches);
    preg_match('/<button\b(?=[^>]*@click="undoFormulaRowRemoval\(\)")(?=[^>]*class="([^"]+)")[^>]*>/s', $bottomActionBarSource, $undoButtonMatches);
    preg_match('/<button\b(?=[^>]*@click="removedFormulaRowUndo = null")(?=[^>]*class="([^"]+)")[^>]*>/s', $bottomActionBarSource, $dismissButtonMatches);

    expect($statusMatches[1] ?? '')
        ->toContain('flex items-center gap-2')
        ->toContain('py-1.5')
        ->not->toContain('flex-wrap')
        ->not->toContain('py-2.5');

    expect($undoButtonMatches[1] ?? '')
        ->toContain('min-h-8')
        ->not->toContain('min-h-11');

    expect($dismissButtonMatches[1] ?? '')
        ->toContain('size-8')
        ->and($bottomActionBarSource)
        ->toContain("aria-label=\"{{ __('navigation.actions.dismiss_notification') }}\"")
        ->toContain('<x-action-icon name="close"');
});

it('keeps only one cosmetic phase chooser open', function (): void {
    $ingredientBrowserSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/ingredient-browser.blade.php'));

    expect($ingredientBrowserSource)
        ->toContain("\$dispatch('phase-chooser-opened', { ingredientId: ingredient.id })")
        ->toContain('@phase-chooser-opened.window="if ($event.detail.ingredientId !== ingredient.id) { open = false; }"')
        ->toContain('if (open) { open = false; } else {')
        ->toContain('open = true; $nextTick(() => reposition());')
        ->toContain('aria-haspopup="menu"')
        ->toContain('@click.outside="open = false"')
        ->toContain('@keydown.escape.window="open = false"')
        ->toContain('@scroll.window="if (open) { reposition(); }"')
        ->toContain('@click.stop="addIngredient(ingredient, phase.key); open = false"')
        ->not->toContain('ingredient-list-scrolled')
        ->not->toContain('x-ref="phaseOptions"');
});

it('keeps formula table lines compact with responsive vertical padding', function () {
    $appStylesSource = file_get_contents(resource_path('css/app.css'));
    $tablePartials = [
        'reaction-core' => view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render(),
        'post-reaction' => view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render(),
        'cosmetic-formula' => view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render(),
    ];
    $soapTableMarkup = implode("\n", array_slice($tablePartials, 0, 2));

    $combinedFormulaTableMarkup = implode("\n", $tablePartials);

    $desktopMediaPattern = '/@media\s*\(min-width:\s*64rem\)\s*\{((?:[^{}]|\{[^{}]*\})*)\}/';
    preg_match_all($desktopMediaPattern, $appStylesSource, $desktopMediaRules);
    $desktopStyles = implode("\n", $desktopMediaRules[1]);
    $baseStyles = preg_replace($desktopMediaPattern, '', $appStylesSource);

    $expectedRules = [
        'base' => [
            'sk-formula-table-y' => ['padding-block' => '10px'],
            'sk-formula-table-row' => ['padding-block' => '8px', 'font-size' => '14px'],
            'sk-formula-table-cell' => ['padding-block' => '8px'],
            'sk-formula-table-action-cell' => ['padding-block' => '0'],
            'sk-formula-table-handle-cell' => ['padding-block' => '0', 'align-items' => 'center'],
            'sk-formula-table-name' => ['line-height' => '18px'],
            'sk-formula-table-inci' => ['font-size' => '12px', 'line-height' => '14px'],
        ],
        'desktop' => [
            'sk-formula-table-row' => ['padding-block' => '0'],
            'sk-formula-table-action-cell' => ['padding-block' => '8px'],
            'sk-formula-table-handle-cell' => ['padding-block' => '8px'],
        ],
    ];

    foreach ($expectedRules as $breakpoint => $rules) {
        $styles = $breakpoint === 'desktop' ? $desktopStyles : $baseStyles;

        foreach ($rules as $selector => $declarations) {
            preg_match('/\.'.preg_quote($selector, '/').'\s*\{([^{}]*)\}/', $styles, $rule);

            expect($rule)->toHaveKey(1);

            foreach ($declarations as $property => $value) {
                expect($rule[1])->toMatch('/(?:^|;)\s*'.preg_quote($property, '/').'\s*:\s*'.preg_quote($value, '/').'\s*;/');
            }
        }
    }

    // Row surface belongs to the bg-* utility on each cell. A background declared
    // here would lose to the utilities layer and misdescribe the cosmetic rows.
    foreach (['.sk-formula-table-cell', '.sk-formula-table-handle-cell'] as $selector) {
        preg_match('/(?:^|[},])\s*'.preg_quote($selector, '/').'\s*\{([^}]*)\}/m', $appStylesSource, $rule);

        expect($rule[1] ?? '')
            ->toContain('padding-block')
            ->not->toContain('background');
    }

    foreach ($tablePartials as $markup) {
        preg_match_all('/(?<![\w:-])class="([^"]*)"/', $markup, $classAttributes);

        foreach ($classAttributes[1] as $classAttribute) {
            $classes = preg_split('/\s+/', trim($classAttribute));

            if (array_intersect($classes, ['sk-formula-table-row', 'sk-formula-table-cell', 'sk-formula-table-handle-cell', 'sk-formula-table-action-cell']) !== []) {
                foreach ($classes as $class) {
                    expect($class)->not->toMatch('/(?:^|:)(?:p|py|pt|pb)-/');
                }
            }

            if (in_array('sk-formula-table-inci', $classes, true) || in_array('sk-formula-table-name', $classes, true)) {
                foreach ($classes as $class) {
                    expect($class)->not->toMatch('/(?:^|:)(?:leading-|text-(?:xs|sm|base|lg|xl|[2-9]xl)(?:$|\/)|text-\[(?:length:|[0-9]))/');
                }
            }
        }

        expect($markup)
            ->toContain('sk-formula-table-y')
            ->toContain('sk-formula-table-row')
            ->toContain('sk-formula-table-cell')
            ->toContain('sk-formula-table-handle-cell')
            ->toContain('px-2.5 text-sm sk-formula-table-row')
            ->toContain('lg:bg-[var(--color-line)] lg:px-0')
            ->toContain('sk-formula-table-action-cell')
            ->toContain('sk-formula-table-name')
            ->toContain('sk-formula-table-inci')
            ->not->toContain('This block is derived from the saponified oils, lye type, water mode, and superfat.')
            ->not->toContain('py-3.5')
            ->not->toContain('lg:py-3.5')
            ->not->toContain('p-2.5 text-sm transition')
            ->not->toContain('px-4 py-4 text-center');
    }

    expect(substr_count($combinedFormulaTableMarkup, 'sk-formula-table-name'))
        ->toBe(4)
        ->and(substr_count($combinedFormulaTableMarkup, 'sk-formula-table-inci'))
        ->toBe(4)
        ->and(substr_count($combinedFormulaTableMarkup, 'sk-formula-table-action-cell'))
        ->toBe(4);

    // Numeric column headers and the totals row rest on the same tokens in both
    // workbenches. Only the unbalanced tone intentionally differs: danger for soap
    // (oils must total 100%), warning for cosmetic.
    foreach (['reaction-core', 'cosmetic-formula'] as $partial) {
        expect($tablePartials[$partial])
            ->toContain('sk-formula-table-y font-medium text-center')
            ->toContain("oilPercentageIsBalanced ? 'bg-[var(--color-field-muted)]'")
            ->not->toContain("oilPercentageIsBalanced ? 'bg-[var(--color-panel-strong)]'");
    }

    expect($soapTableMarkup)
        ->toContain('sk-formula-table-row transition-[background-color,box-shadow] duration-300 motion-reduce:transition-none')
        ->toContain('transition-colors duration-150 motion-reduce:transition-none')
        ->toContain('x-effect="animateAddedIngredientRow($el, row.id)"')
        ->not->toContain('sk-formula-table-row transition motion-safe:will-change-transform');
});

it('centers costing table row contents beside price inputs', function () {
    $costingTab = view('livewire.dashboard.partials.recipe-workbench.costing-tab')->render();

    expect($costingTab)
        ->toContain('flex items-center bg-white px-4 py-3 text-[var(--color-ink-soft)]" x-text="row.phaseLabel"')
        ->toContain('numeric flex items-center bg-white px-4 py-3 text-[var(--color-ink-soft)]"><span class="sk-decimal-aligned"')
        ->toContain('x-text="`${format(row.percentage, 2)}${row.percentageLabel}`"')
        ->toContain('flex items-center bg-white px-3 py-3')
        ->toContain('x-text="format(lineCostForRow(row), 2)"')
        ->not->toContain('numeric bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(lineCostForRow(row), 2)}`"');
});

it('keeps currency in the packaging headers and out of the packaging cells', function () {
    $costingTab = view('livewire.dashboard.partials.recipe-workbench.costing-tab')->render();

    expect($costingTab)
        ->toContain('x-text="t(\'costing.packaging.cost_per_unit\', { currency: costingCurrency })"')
        ->toContain('x-text="t(\'costing.packaging.batch_cost\', { currency: costingCurrency })"')
        ->toContain('x-text="format(packagingCostPerFinishedUnitForRow(row), 2)"></span></div>')
        ->not->toContain('<span x-text="costingCurrency"></span><span class="sk-decimal-aligned" :style="decimalAlignmentStyle(packagingCostPerFinishedUnitForRow(row))"')
        ->not->toContain('<span x-text="costingCurrency"></span><span class="sk-decimal-aligned" :style="decimalAlignmentStyle(packagingBatchCostForRow(row))"');
});

it('formats the packaging unit price input with locale decimals', function () {
    $costingTab = view('livewire.dashboard.partials.recipe-workbench.costing-tab')->render();

    expect($costingTab)
        ->toContain(':value="row.unit_cost === null || row.unit_cost === \'\' ? \'\' : format(row.unit_cost, 2)"')
        ->toContain('@change="updatePackagingUnitCost(row, $event.target.value)"')
        ->not->toContain('x-model="row.unit_cost"');
});

it('describes soap post-reaction percentages as oil-basis percentages', function () {
    $postReaction = view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render();
    $formulaSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings')->render();
    $workbenchViewSource = file_get_contents(resource_path('views/livewire/dashboard/recipe-workbench.blade.php'));
    $formulaSectionSource = file_get_contents(resource_path('js/recipe-workbench/sections/formula-section.js'));
    $presentationSectionSource = file_get_contents(resource_path('js/recipe-workbench/sections/presentation-section.js'));

    expect($postReaction)
        ->toContain('% of oils')
        ->toContain('% oils')
        ->not->toContain('% of base')
        ->not->toContain('% base')
        ->and($formulaSettings)
        ->toContain('% of oils')
        ->not->toContain('% of base')
        ->and($formulaSectionSource)
        ->toContain("'% oils'")
        ->not->toContain("'% base'")
        ->and($presentationSectionSource)
        ->toContain('Additives (% oils)')
        ->not->toContain('Additives (% base)')
        ->and($workbenchViewSource)
        ->toContain('@dragover.window="autoScrollDuringRowDrag($event)"');
});

it('keeps dashboard select chevrons away from the right edge', function () {
    $appStylesSource = file_get_contents(resource_path('css/app.css'));

    expect($appStylesSource)
        ->toContain('[data-app-shell] select:not([multiple]):not([size])')
        ->toContain('appearance: none')
        ->toContain('padding-inline-end: 3rem')
        ->toContain('background-position: right 1.25rem center');
});

it('keeps instructions copy and the save bar in context', function () {
    $instructionsMedia = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/instructions-media.blade.php'));
    $renderedInstructionsMedia = Blade::render(
        str_replace('{{ $this->form }}', '', $instructionsMedia),
        [
            'recipeContentStatus' => 'idle',
            'workbench' => ['recipe' => null],
        ],
    );

    expect($instructionsMedia)
        ->toContain("__('workbench.instructions.title')")
        ->toContain("__('workbench.instructions.intro')")
        ->toContain('max-w-[75ch]')
        ->toContain('<x-workflow-action-bar max-width="max-w-app" data-instructions-save-bar>')
        ->toContain('space-y-6 pb-24 pt-5')
        ->toContain('class="sk-btn sk-btn-primary"')
        ->not->toContain('sticky bottom-3 z-30')
        ->not->toContain('fixed ')
        ->not->toContain('left-[var(--app-sidebar-width,0rem)]')
        ->not->toContain('pointer-events-none')
        ->not->toContain('pointer-events-auto')
        ->not->toContain('pb-32')
        ->not->toContain('pb-36')
        ->toContain("__('workbench.instructions.draft_text_help')")
        ->not->toContain('Content &amp; Media')
        ->not->toContain('Save content and media')
        ->not->toContain('Save the formula above to attach this content.');

    expect($renderedInstructionsMedia)
        ->toContain('Instructions &amp; media')
        ->toContain('Product page')
        ->toContain('You can start writing now. Save the formula before attaching images.')
        ->toContain('Unsaved changes')
        ->not->toContain('Content &amp; Media');
});

it('organizes saved instructions and media with responsive schema contracts', function () {
    $owner = User::factory()->create();
    $productFamily = ProductFamily::factory()->create([
        'name' => 'Soap',
        'slug' => 'soap',
    ]);
    $recipe = Recipe::factory()->create([
        'product_family_id' => $productFamily->id,
        'owner_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $component = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe]);
    $form = $component->instance()->form;
    $description = $form->getComponent('description');
    $featuredImage = $form->getComponent('featured_media_asset_id');
    $manufacturingInstructions = $form->getComponent('manufacturing_instructions');
    $presentationGrid = $description->getContainer()->getParentComponent();
    $procedureGrid = $manufacturingInstructions->getContainer()->getParentComponent();
    $procedureSection = $form->getComponent(
        fn ($schemaComponent): bool => $schemaComponent instanceof Section
            && $schemaComponent->getHeading() === __('workbench.instructions.procedure_label'),
    );
    $orderedContentFields = collect($form->getFlatFields())
        ->map(fn (Field $field): string => $field->getName())
        ->filter(fn (string $name): bool => in_array($name, [
            'description',
            'featured_media_asset_id',
            'manufacturing_instructions',
        ], true))
        ->values()
        ->all();

    expect($orderedContentFields)->toBe([
        'description',
        'featured_media_asset_id',
        'manufacturing_instructions',
    ])
        ->and($description)->toBeInstanceOf(RichEditor::class)
        ->and($featuredImage)->toBeInstanceOf(MediaAssetPicker::class)
        ->and($manufacturingInstructions)->toBeInstanceOf(RichEditor::class)
        ->and($form->getComponent('manufacturing_media_asset_ids'))->toBeNull()
        ->and($presentationGrid)->toBeInstanceOf(Grid::class)
        ->and($presentationGrid->getColumns('default'))->toBe(1)
        ->and($presentationGrid->getColumns('lg'))->toBe(12)
        ->and($description->getColumnSpan('lg'))->toBe(8)
        ->and($featuredImage->getColumnSpan('lg'))->toBe(4)
        ->and($procedureSection)->toBeInstanceOf(Section::class)
        ->and($procedureGrid)->toBeInstanceOf(Grid::class)
        ->and($procedureGrid->getColumns('default'))->toBe(1)
        ->and($procedureGrid->getColumns('lg'))->toBe(12)
        ->and($manufacturingInstructions->getColumnSpan('lg'))->toBe(12)
        ->and($manufacturingInstructions->isLabelHidden())->toBeTrue()
        ->and($description->hasResizableImages())->toBeFalse()
        ->and($manufacturingInstructions->hasResizableImages())->toBeTrue()
        ->and($manufacturingInstructions->getExtraInputAttributes()['class'])->toContain(
            '[&_.fi-fo-rich-editor-content]:mx-auto',
            '[&_.fi-fo-rich-editor-content]:max-w-[680px]',
            '[&_.fi-fo-rich-editor-content_img]:!h-auto',
        )
        ->and($description->getExtraInputAttributes()['class'])
        ->not->toContain('[&_.fi-fo-rich-editor-content]:max-w-[680px]')
        ->and($manufacturingInstructions->getPlugins())
        ->toContainOnlyInstancesOf(MediaLibraryRichContentPlugin::class)
        ->and(collect($manufacturingInstructions->getToolbarButtons())->flatten()->all())
        ->toContain('insertFromMediaLibrary');

    $renderedWorkbench = $component->html();
    $visibleWorkbench = preg_replace(
        '/<(?<tag>[a-z0-9]+)[^>]*class="[^"]*fi-sr-only[^"]*"[^>]*>.*?<\/\\k<tag>>/si',
        '',
        $renderedWorkbench,
    );

    expect($renderedWorkbench)
        ->toMatch('/<(?<tag>[a-z0-9]+)[^>]*class="[^"]*fi-fo-field-label[^"]*fi-sr-only[^"]*"[^>]*>\s*Manufacturing procedure\s*<\/\\k<tag>>/')
        ->and(substr_count(strip_tags($visibleWorkbench), 'Manufacturing procedure'))->toBe(1);
});

it('keeps rich content text-only and enables library pickers after the recipe has been saved', function () {
    $owner = User::factory()->create();
    $productFamily = ProductFamily::factory()->create([
        'name' => 'Soap',
        'slug' => 'soap',
    ]);
    $recipe = Recipe::factory()->create([
        'product_family_id' => $productFamily->id,
        'owner_id' => $owner->id,
    ]);

    $this->actingAs($owner);

    $unsavedForm = Livewire::test(RecipeWorkbench::class, ['productFamilySlug' => 'soap'])
        ->instance()
        ->form;
    $savedForm = Livewire::test(RecipeWorkbench::class, ['recipe' => $recipe])
        ->instance()
        ->form;

    $unsavedDescription = $unsavedForm->getComponent('description');
    $unsavedManufacturingInstructions = $unsavedForm->getComponent('manufacturing_instructions');
    $unsavedFeaturedImage = $unsavedForm->getComponent('featured_media_asset_id');
    $savedDescription = $savedForm->getComponent('description');
    $savedManufacturingInstructions = $savedForm->getComponent('manufacturing_instructions');
    $savedFeaturedImage = $savedForm->getComponent('featured_media_asset_id');

    expect($unsavedDescription)->toBeInstanceOf(RichEditor::class)
        ->and($unsavedDescription->isEnabled())->toBeTrue()
        ->and($unsavedDescription->hasFileAttachments())->toBeFalse()
        ->and(collect($unsavedDescription->getToolbarButtons())->flatten()->all())->not->toContain('attachFiles')
        ->and($unsavedManufacturingInstructions)->toBeInstanceOf(RichEditor::class)
        ->and($unsavedManufacturingInstructions->isEnabled())->toBeTrue()
        ->and($unsavedManufacturingInstructions->hasFileAttachments())->toBeFalse()
        ->and(collect($unsavedManufacturingInstructions->getToolbarButtons())->flatten()->all())->not->toContain('attachFiles')
        ->and($unsavedFeaturedImage)->toBeInstanceOf(MediaAssetPicker::class)
        ->and($unsavedFeaturedImage->isDisabled())->toBeTrue()
        ->and($unsavedForm->getComponent('manufacturing_media_asset_ids'))->toBeNull()
        ->and($savedDescription)->toBeInstanceOf(RichEditor::class)
        ->and($savedDescription->hasFileAttachments())->toBeFalse()
        ->and(collect($savedDescription->getToolbarButtons())->flatten()->all())->not->toContain('attachFiles')
        ->and($savedManufacturingInstructions)->toBeInstanceOf(RichEditor::class)
        ->and($savedManufacturingInstructions->hasFileAttachments())->toBeFalse()
        ->and(collect($savedManufacturingInstructions->getToolbarButtons())->flatten()->all())->not->toContain('attachFiles')
        ->and($savedFeaturedImage)->toBeInstanceOf(MediaAssetPicker::class)
        ->and($savedFeaturedImage->isEnabled())->toBeTrue()
        ->and($savedForm->getComponent('manufacturing_media_asset_ids'))->toBeNull();
});

it('keeps the desktop ingredient rail row-bounded and moves soap fatty acids below the table on mobile', function () {
    $formulaTabSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php'));
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();

    expect($formulaTabSource)
        ->toContain('@5xl/workbench:grid-cols-[19rem_minmax(0,1fr)]')
        ->toContain('order-1 min-w-0 @5xl/workbench:col-start-1')
        ->toContain('order-2 min-w-0 space-y-4 @5xl/workbench:col-start-2')
        ->toContain('class="order-1 min-w-0 @5xl/workbench:col-start-1 @5xl/workbench:row-start-1 space-y-4 @5xl/workbench:sticky @5xl/workbench:top-4 @5xl/workbench:self-start"')
        ->toContain("id=\"formula-ingredient-browser\" x-ref=\"ingredientBrowserRail\" x-cloak :class=\"ingredientBrowserOpen ? 'block' : 'hidden @5xl/workbench:block'\"")
        ->toContain('class="hidden @5xl/workbench:block"')
        ->toContain('@5xl/workbench:hidden')
        ->toContain('data-ingredient-browser-disclosure')
        ->not->toContain('lg:max-h-[calc(100vh-7rem)]')
        ->not->toContain('lg:overflow-y-auto')
        ->not->toContain('lg:pr-1')
        ->not->toContain('x-ref="ingredientBrowserRail" x-cloak class="@5xl/workbench:sticky')
        ->not->toContain('class="hidden xl:block"')
        ->not->toContain('class="xl:hidden"');

    expect($formulaTabSource)->toMatch('/<section\b[^>]*>\s*<div[^>]*@5xl\/workbench:sticky/s');

    expect(substr_count($formulaTabSource, '@5xl/workbench:sticky'))->toBe(1);

    expect($ingredientBrowser)
        ->toContain('Add ingredients')
        ->toContain('text-lg font-semibold')
        ->toContain('data-search-combobox="ingredient-category-search"')
        ->not->toContain('Filtered by category')
        ->not->toContain('mt-2 text-xl font-semibold')
        ->toContain('max-h-[18rem]')
        ->toContain('md:max-h-[22rem]')
        ->toContain('lg:max-h-[24rem]')
        ->toContain('xl:max-h-[600px]')
        ->not->toContain('Fatty acid profile');

    expect(strpos($formulaTabSource, 'aria-controls="formula-ingredient-browser"'))
        ->toBeGreaterThan(strpos($formulaTabSource, 'data-ingredient-browser-disclosure'))
        ->toBeLessThan(strpos($formulaTabSource, 'post-reaction'));
});

it('keeps the narrow ingredient disclosure discoverable while sharing one catalog instance', function () {
    $formulaTabSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php'));
    $componentSource = file_get_contents(resource_path('js/recipe-workbench/component.js'));

    expect($formulaTabSource)
        ->toContain('data-ingredient-browser-disclosure')
        ->toContain(':aria-expanded="ingredientBrowserOpen.toString()"')
        ->toContain('<x-action-icon name="plus" x-cloak x-show="! ingredientBrowserOpen" />')
        ->toContain('<x-action-icon name="minus" x-cloak x-show="ingredientBrowserOpen" />')
        ->toContain('id="formula-ingredient-browser"')
        ->toContain('x-ref="ingredientBrowserRail"')
        ->toContain(":class=\"ingredientBrowserOpen ? 'block' : 'hidden @5xl/workbench:block'\"")
        ->not->toContain('id="formula-ingredient-browser" x-show="ingredientBrowserOpen"')
        ->not->toContain('<details');

    expect($componentSource)->toContain('ingredientBrowserOpen: false');

    expect(substr_count($formulaTabSource, "@include('livewire.dashboard.partials.recipe-workbench.ingredient-browser')"))
        ->toBe(1);
});

it('uses accessible currentColor SVG action icons across the soap workbench controls', function (): void {
    $iconSource = file_get_contents(resource_path('views/components/action-icon.blade.php'));
    $formulaTabSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php'));
    $ingredientBrowser = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/ingredient-browser.blade.php'));
    $formulaSettings = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php'));
    $reactionCore = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/reaction-core.blade.php'));
    $postReaction = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/post-reaction.blade.php'));
    $cosmeticFormula = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/cosmetic-formula.blade.php'));
    $rowActions = file_get_contents(resource_path('views/components/recipe-workbench/formula-row-actions.blade.php'));

    expect($iconSource)
        ->toContain('stroke="currentColor"')
        ->toContain('aria-hidden="true"')
        ->toContain('focusable="false"')
        ->toContain("'drag'")
        ->toContain("'info'")
        ->toContain("'close'")
        ->toContain("'plus'")
        ->toContain("'minus'")
        ->toContain("'more-horizontal'")
        ->toContain("'chevron-down'");

    foreach (['drag', 'info', 'close', 'plus', 'minus', 'more-horizontal', 'chevron-down'] as $name) {
        $renderedIcon = Blade::render('<x-action-icon name="'.$name.'" />');

        expect($renderedIcon)
            ->toContain('<svg')
            ->toContain('stroke="currentColor"')
            ->toContain('aria-hidden="true"')
            ->toContain('focusable="false"');
    }

    expect($formulaTabSource)
        ->toContain('<x-action-icon name="plus" x-cloak x-show="! ingredientBrowserOpen" />')
        ->toContain('<x-action-icon name="minus" x-cloak x-show="ingredientBrowserOpen" />')
        ->not->toContain("x-text=\"ingredientBrowserOpen ? '−' : '+'\"");

    expect($ingredientBrowser)
        ->toContain('<x-action-icon name="info" />')
        ->toContain('<x-action-icon name="plus" />')
        ->not->toContain('<span>+</span>');

    expect($formulaSettings)
        ->toContain('<x-action-icon name="close" />')
        ->not->toContain('>×</button>');

    expect($reactionCore)
        ->toContain('<x-action-icon name="drag" />')
        ->toContain('<x-action-icon name="info" />')
        ->not->toContain('<x-action-icon name="close" />')
        ->not->toContain('⋮⋮')
        ->not->toContain('>×</button>');

    expect($postReaction)
        ->toContain('<x-action-icon name="drag" />')
        ->not->toContain('<x-action-icon name="close" />')
        ->not->toContain('⋮⋮')
        ->not->toContain('>×</button>');

    expect($cosmeticFormula)
        ->toContain('<x-action-icon name="drag" />')
        ->toContain('<x-action-icon name="info" />')
        ->not->toContain('<x-action-icon name="close" />')
        ->not->toContain('⋮⋮')
        ->not->toContain('>×</button>')
        ->and($rowActions)
        ->toContain('<x-action-icon name="more-horizontal" />');
});

it('allocates ingredient rail width and gutter from the real workbench width', function () {
    $formulaTabSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php'));

    expect($formulaTabSource)
        ->toContain('@5xl/workbench:grid-cols-[19rem_minmax(0,1fr)]')
        ->toContain('@5xl/workbench:gap-6')
        ->toContain('@7xl/workbench:gap-8')
        ->not->toContain('@min-[96rem]/workbench:grid-cols-[22rem_minmax(0,1fr)]')
        ->not->toContain('@min-[96rem]/workbench:gap-10')
        ->not->toContain('2xl:grid-cols-[22rem_minmax(0,1fr)]');
});

it('keeps compact ingredient names readable and moves inci into the inspector', function () {
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();

    expect($ingredientBrowser)
        ->toContain('line-clamp-2')
        ->toContain(':title="ingredient.name"')
        ->toContain('text-[13px] font-semibold')
        ->toContain('sk-ingredient-image-tile')
        ->toContain('grid size-9 place-items-center')
        ->toContain('User-created or modified ingredient')
        ->toContain('<p class="sk-eyebrow">Ingredient</p>')
        ->toContain('x-ref="ingredientInspectorPanel"')
        ->toContain('role="dialog"')
        ->toContain('aria-label="Ingredient details"')
        ->toContain('const panelHeight = this.$refs.ingredientInspectorPanel?.offsetHeight ?? 0')
        ->toContain('max-h-[calc(100dvh-2rem)]')
        ->toContain('rounded-xl bg-[var(--color-panel)] px-3 py-2')
        ->toContain('text-sm font-semibold leading-snug text-[var(--color-ink-strong)]" x-text="ingredient.name"')
        ->toContain('text-xs leading-4 text-[var(--color-ink-soft)]" x-text="ingredient.inci_name ||')
        ->and(substr_count($ingredientBrowser, "ingredient.inci_name || 'INCI not entered yet'"))
        ->toBe(1);
});

it('shares the raised treatment across ingredient info popovers', function (): void {
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $appStylesSource = file_get_contents(resource_path('css/app.css'));

    expect(substr_count($ingredientBrowser, 'sk-ingredient-info-popover'))->toBe(1)
        ->and(substr_count($reactionCore, 'sk-ingredient-info-popover'))->toBe(1)
        ->and($appStylesSource)
        ->toContain('.sk-ingredient-info-popover')
        ->toContain('border-radius: 1.25rem')
        ->toContain('box-shadow: var(--shadow-card);');
});

it('keeps ingredient browser filters visible and pill shaped while focused', function () {
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();
    $appStylesSource = file_get_contents(resource_path('css/app.css'));
    $genericWorkbenchFocusPosition = strpos($appStylesSource, '.sk-workbench :is(button:not([role="tab"]), input:not([type="range"]):not(.sk-formula-title-control):not(.sk-field-control):not(.sk-input), select, textarea, a, summary):focus-visible');
    $ingredientFilterFocusPosition = strrpos($appStylesSource, '.sk-workbench .sk-ingredient-filter-control:focus-visible');

    expect($ingredientBrowser)
        ->toContain('sk-ingredient-filter-control w-full px-4 py-3 text-sm')
        ->and(substr_count($ingredientBrowser, 'sk-ingredient-filter-control'))
        ->toBe(1)
        ->and($appStylesSource)
        ->toContain('.sk-workbench .sk-ingredient-filter-control')
        ->toContain('border-radius: 1.15rem')
        ->toContain('box-shadow: inset 0 0 0 1px')
        ->toContain('.sk-workbench .sk-ingredient-filter-control:focus-visible')
        ->toContain('outline-style: none !important')
        ->toContain('outline: none !important')
        ->not->toContain('box-shadow: inset 0 0 0 2px')
        ->and($ingredientFilterFocusPosition)
        ->toBeGreaterThan($genericWorkbenchFocusPosition);
});

it('uses a slim radius respecting inset ring for focused workbench controls except tabs', function () {
    $appStylesSource = file_get_contents(resource_path('css/app.css'));

    preg_match(
        '/\\.sk-workbench :is\\(button:not\\(\\[role="tab"\\]\\), input:not\\(\\[type="range"\\]\\):not\\(\\.sk-formula-title-control\\):not\\(\\.sk-field-control\\):not\\(\\.sk-input\\), select, textarea, a, summary\\):focus-visible \\{(?<rule>.*?)\\n\\}/s',
        $appStylesSource,
        $matches,
    );

    expect($matches)
        ->toHaveKey('rule')
        ->and($matches['rule'] ?? '')
        ->toContain('box-shadow: inset 0 0 0 1px')
        ->toContain('outline: none !important;')
        ->not->toContain('outline-style: solid !important;');
});

it('keeps field focus rings off range tracks and marks the focused thumb for keyboard users', function () {
    $formulaSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings')->render();
    $appStylesSource = file_get_contents(resource_path('css/app.css'));

    expect(substr_count($formulaSettings, 'type="range"'))
        ->toBe(2)
        ->and($appStylesSource)
        ->toContain('input:not([type="range"]):not(.sk-formula-title-control)')
        ->toContain('.sk-workbench input[type="range"]:focus')
        ->toContain('.sk-workbench input[type="range"]:focus-visible::-webkit-slider-thumb')
        ->toContain('.sk-workbench input[type="range"]:focus-visible::-moz-range-thumb')
        ->toContain('0 0 0 4px var(--color-active)')
        ->not->toContain('button:not([role="tab"]), input, select, textarea');
});

it('uses an underline active state and keyboard-only focus treatment for workbench tabs', function () {
    $navigation = view('livewire.dashboard.partials.recipe-workbench.navigation')->render();
    $appStylesSource = file_get_contents(resource_path('css/app.css'));

    expect($navigation)
        ->toContain('sk-workbench-tab')
        ->toContain(":class=\"{ 'is-active': activeWorkbenchTab === 'formula' }\"")
        ->not->toContain('bg-[var(--color-active)]')
        ->and($appStylesSource)
        ->toContain('.sk-workbench .sk-workbench-tab.is-active')
        ->toContain('.sk-workbench .sk-workbench-tab.is-active::before')
        ->toContain('background-color: transparent')
        ->toContain('color: var(--color-accent-strong) !important')
        ->toContain('background-color: var(--color-active);')
        ->toContain('height: 0.125rem')
        ->toContain('.sk-workbench :is(button:not([role="tab"]), input:not([type="range"]):not(.sk-formula-title-control):not(.sk-field-control):not(.sk-input), select, textarea, a, summary):focus-visible')
        ->toContain('.sk-workbench [role="tab"]:focus-visible')
        ->toContain('outline: 1px solid var(--color-active);')
        ->toContain('outline-offset: 2px;')
        ->not->toContain('background-color: var(--color-active-strong)')
        ->not->toContain('.sk-workbench :is(button, input, select, textarea, a, summary):focus-visible {');
});

it('uses the shared rounded focus surface for packaging catalog search', function () {
    $packagingTab = view('livewire.dashboard.partials.recipe-workbench.packaging-tab')->render();
    $appStylesSource = file_get_contents(resource_path('css/app.css'));

    expect($packagingTab)
        ->toContain('sk-combobox-control')
        ->not->toContain('focus-within:outline-2')
        ->and($appStylesSource)
        ->toContain('.sk-combobox-control:focus-within')
        ->toContain('.sk-combobox-control input:focus-visible')
        ->toContain('box-shadow: none;');
});

it('shows the formula title underline only while the field is focused', function () {
    $formulaHeader = view('livewire.dashboard.partials.recipe-workbench.header')->render();
    $appStylesSource = file_get_contents(resource_path('css/app.css'));

    preg_match(
        '/\\.sk-workbench \\.sk-formula-title-control \\{(?<rule>.*?)\\n\\}/s',
        $appStylesSource,
        $baseTitleRule,
    );
    preg_match(
        '/\\.sk-workbench \\.sk-formula-title-control:focus,\\n\\.sk-workbench \\.sk-formula-title-control:focus-visible \\{(?<rule>.*?)\\n\\}/s',
        $appStylesSource,
        $focusedTitleRule,
    );

    expect($formulaHeader)
        ->toContain('sk-formula-title-control')
        ->toContain('pb-2 pt-1')
        ->not->toContain('border-b-2')
        ->and($baseTitleRule['rule'] ?? '')
        ->toContain('border: 0 !important;')
        ->toContain('border-radius: 0 !important;')
        ->not->toContain('border-bottom:')
        ->and($focusedTitleRule['rule'] ?? '')
        ->toContain('border-bottom: 1px solid color-mix(')
        ->toContain('outline: none !important;')
        ->and($appStylesSource)
        ->not->toContain('inset 0 -1px 0')
        ->not->toContain('input:not([type="range"]), select, textarea, a, summary):focus-visible')
        ->toContain('.sk-workbench .sk-formula-title-control:focus-visible');
});

it('keeps a disabled lock control visible until a new formula is saved', function () {
    $formulaHeader = view('livewire.dashboard.partials.recipe-workbench.header')->render();

    expect($formulaHeader)
        ->toContain('Save the product before locking it.')
        ->toContain('disabled')
        ->toContain('Lock product')
        ->and(strpos($formulaHeader, 'Lock product'))
        ->toBeLessThan(strpos($formulaHeader, 'More actions'));
});

it('uses the shared compact button sizing for workbench actions', function () {
    $formulaHeader = view('livewire.dashboard.partials.recipe-workbench.header')->render();
    $formulaSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings')->render();
    $bottomActionBar = view('livewire.dashboard.partials.recipe-workbench.formula-bottom-action-bar')->render();
    $appStylesSource = file_get_contents(resource_path('css/app.css'));

    expect(substr_count($formulaHeader, 'sk-btn'))
        ->toBeGreaterThanOrEqual(3)
        ->and($formulaHeader)
        ->not->toContain('class="rounded-lg px-5 py-3 text-sm font-semibold transition"')
        ->not->toContain('bg-transparent px-4 py-3 text-sm font-medium')
        ->and($formulaSettings)
        ->toContain('class="sk-btn shrink-0 bg-[var(--color-field-muted)]')
        ->and($bottomActionBar)
        ->toContain('class="sk-btn bg-[var(--color-field-muted)]')
        ->toContain('class="sk-btn"')
        ->and($appStylesSource)
        ->toContain(".sk-btn,\n    .sk-action-link {")
        ->toContain('min-height: 2.5rem;')
        ->toContain('padding: 0.5rem 1rem;');
});

it('uses slim application focus indicators', function () {
    $appStylesSource = file_get_contents(resource_path('css/app.css'));
    $sharedStylesSource = file_get_contents(resource_path('css/shared/soapkraft.css'));
    $filamentStylesSource = file_get_contents(resource_path('css/shared/filament-soapkraft.css'));

    expect($appStylesSource)
        ->toContain('outline: 1px solid var(--color-accent);')
        ->not->toContain('outline: 2px solid var(--color-accent);')
        ->and($sharedStylesSource)
        ->toContain("button:focus-visible,\na:focus-visible {\n    outline: 1px solid var(--color-accent);")
        ->and($filamentStylesSource)
        ->toContain('box-shadow: inset 0 0 0 1px var(--color-accent);')
        ->not->toContain('box-shadow: inset 0 0 0 2px var(--color-accent);');
});

it('keeps workbench card subheadings at the compact card title size', function () {
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $cosmeticFormula = view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render();
    $formulaAnalysis = view('livewire.dashboard.partials.recipe-workbench.formula-analysis')->render();

    $combinedCardMarkup = implode("\n", [
        $ingredientBrowser,
        $reactionCore,
        $cosmeticFormula,
        $formulaAnalysis,
    ]);

    expect($combinedCardMarkup)
        ->toContain('text-lg font-semibold text-[var(--color-ink-strong)]')
        ->not->toMatch('/<h3[^>]*class="[^"]*text-xl font-semibold text-\[var\(--color-ink-strong\)\]/');
});

it('renders the lye and water summary without a redundant outer card', function (): void {
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();

    expect($reactionCore)
        ->toContain('<section class="mt-5" aria-labelledby="lye-water-summary-heading">')
        ->toContain('<p id="lye-water-summary-heading" class="sk-eyebrow">')
        ->toContain('<template x-for="card in lyeSummaryCards"')
        ->toContain('class="sk-inset flex min-h-[4.25rem] min-w-0 flex-col px-3 py-2.5"')
        ->not->toContain('class="sk-inset mt-5 p-4"');
});

it('keeps live formula diagnostics in a compact bottom save bar without SAP gap warnings', function () {
    $formulaTabSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php'));
    $formulaSectionSource = file_get_contents(resource_path('js/recipe-workbench/sections/formula-section.js'));
    $componentSource = file_get_contents(resource_path('js/recipe-workbench/component.js'));
    $appSource = file_get_contents(resource_path('js/app.js'));
    $bottomActionBar = view('livewire.dashboard.partials.recipe-workbench.formula-bottom-action-bar')->render();
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();

    expect($formulaTabSource)
        ->not->toContain('recipe-workbench.formula-diagnostics-rail')
        ->toContain('recipe-workbench.formula-bottom-action-bar')
        ->toContain('pb-40 sm:pb-28')
        ->and($bottomActionBar)
        ->toContain('Formula save bar')
        ->toContain('fixed bottom-0 left-0 right-0')
        ->toContain('z-30')
        ->toContain('lg:left-[var(--app-sidebar-width,0rem)]')
        ->toContain('bg-[color-mix(in_oklab,var(--color-panel)_72%,transparent)]')
        ->toContain('backdrop-blur-md')
        ->toContain('lg:flex-nowrap')
        ->toContain('overflow-x-auto')
        ->toContain('formulaDiagnosticCards')
        ->toContain('pulseDiagnosticValue')
        ->toContain('motion-safe:')
        ->toContain('Ingredients with a zero quantity')
        ->toContain('Formula status')
        ->toContain("t('cosmetic.show_details')")
        ->toContain("t('cosmetic.hide_details')")
        ->toContain('formulaDiagnosticSummaryCards')
        ->toContain('toggleFormulaDiagnostics()')
        ->toContain('aria-controls="formula-bottom-diagnostics-details"')
        ->toContain('absolute inset-x-0 bottom-full')
        ->toContain('max-h-[min(60dvh,28rem)]')
        ->toContain('overflow-y-auto overscroll-contain')
        ->toContain(":class=\"isFormulaDiagnosticsOpen ? 'grid-rows-[1fr]' : 'grid-rows-[0fr] invisible'\"")
        ->toContain(':aria-expanded="isFormulaDiagnosticsOpen.toString()"')
        ->toContain('publish()')
        ->toContain('Save')
        ->not->toContain('requestOfficialRecipeSave()')
        ->not->toContain('Save draft')
        ->not->toContain('Save as reference')
        ->not->toContain('SAP')
        ->not->toContain('Missing KOH SAP')
        ->and($formulaSectionSource)
        ->toContain('get formulaDiagnosticCards()')
        ->toContain('get formulaDiagnosticSummaryCards()')
        ->toContain('zeroQuantityRows()')
        ->toContain("label: this.t('status.changes')")
        ->toContain("this.t('status.save_failed')")
        ->toContain("this.t('status.saved')")
        ->toContain("matchMedia('(prefers-reduced-motion: reduce)')")
        ->not->toContain('Missing KOH SAP')
        ->not->toContain('Synced')
        ->not->toContain('Draft state')
        ->and($componentSource)
        ->toContain('formulaDiagnosticsPreferenceKey')
        ->toContain('isFormulaDiagnosticsOpen')
        ->toContain('preferredFormulaDiagnosticsOpen')
        ->toContain('return false;')
        ->not->toContain("matchMedia?.('(min-width: 1024px)')")
        ->toContain('persistFormulaDiagnosticsPreference')
        ->toContain('toggleFormulaDiagnostics')
        ->and($appSource)
        ->toContain("document.documentElement.style.setProperty('--app-sidebar-width', isDesktop && nextOpen ? '17rem' : '0rem')")
        ->and($reactionCore)
        ->not->toContain('Missing KOH SAP');
});

it('shows the cured soap composition at full width above restrictions and gives cosmetics one descending ingredient table', function () {
    $outputTab = view('livewire.dashboard.partials.recipe-workbench.output-tab', [
        'isPublicCalculator' => true,
    ])->render();
    $cosmeticOutputTab = view('livewire.dashboard.partials.recipe-workbench.output-tab', [
        'isCosmeticWorkbench' => true,
        'isPublicCalculator' => true,
    ])->render();
    $presentationSectionSource = file_get_contents(resource_path('js/recipe-workbench/sections/presentation-section.js'));

    expect($outputTab)
        ->not->toContain('290px')
        ->toContain('Cured soap output')
        ->toContain('Cured soap composition')
        ->toContain('curedSoapIngredientRows')
        ->toContain('Formula %')
        ->toContain('% soap')
        ->toContain('align-middle')
        ->toContain('output-soap-composition-heading')
        ->toContain('Cured basis')
        ->toContain('regulatoryRegime = regime.code')
        ->toContain('Label market')
        ->toContain('indicative and informative')
        ->not->toContain('Declared allergens')
        ->not->toContain('Integrated ingredients')
        ->not->toContain('Mise en oeuvre')
        ->not->toContain('Ingredient basis')
        ->not->toContain('incorporatedIngredientRows')
        ->not->toContain('Batch ingredients')
        ->not->toContain('Production tables')
        ->not->toContain('lg:grid-cols-2')
        ->and(substr_count($outputTab, 'Cured soap composition'))
        ->toBe(2)
        ->and(strpos($outputTab, 'Cured soap composition'))
        ->toBeLessThan(strpos($outputTab, 'Restrictions'))
        ->and($cosmeticOutputTab)
        ->toContain('Formula composition')
        ->not->toContain('Ingredient output')
        ->not->toContain('Formula output')
        ->toContain('Cosmetic formula')
        ->toContain('Formula quantity')
        ->toContain('Ingredients are ordered from highest to lowest formula share. The common name appears below the INCI labeling name.')
        ->toContain('cosmeticOutputIngredientRows')
        ->toContain('format(row.percentage, 2)')
        ->toContain('format(row.weight, 3)')
        ->toContain('formatPercentageTotal(totalOilPercentage())')
        ->toContain('formatPercentageTotal(cosmeticOutputIngredientTotalPercent)')
        ->toContain('x-text="`${format(cosmeticOutputIngredientTotalWeight, 3)} ${oilUnit}`"')
        ->toContain('x-text="format(cosmeticOutputIngredientTotalWeight, 3)"')
        ->toContain('% formula')
        ->toContain('Formula total')
        ->toContain('Add ingredients to build the formula composition.')
        ->not->toContain('Descending')
        ->not->toContain('Production tables')
        ->not->toContain('Batch ingredients')
        ->and($presentationSectionSource)
        ->toContain('get cosmeticOutputIngredientRows()')
        ->toContain('return right.percentage - left.percentage')
        ->toContain('get cosmeticOutputIngredientTotalWeight()')
        ->toContain('get cosmeticOutputIngredientTotalPercent()');
});

it('uses the cured soap basis for soap output percentages', function () {
    $outputTab = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php'));
    $ingredientListPreview = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/ingredient-list-preview.blade.php'));
    $presentationSection = file_get_contents(resource_path('js/recipe-workbench/sections/presentation-section.js'));

    expect($outputTab)
        ->toContain("__('workbench.output.soap.title')")
        ->toContain("__('workbench.output.soap.cured_basis')")
        ->toContain("__('workbench.output.common.soap_percent')")
        ->toContain('x-for="(row, index) in curedSoapIngredientRows"')
        ->and(file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/label-market-switcher.blade.php')))
        ->toContain("__('workbench.output.common.label_market')")
        ->toContain('@click="regulatoryRegime = regime.code"')
        ->not->toContain("__('workbench.output.soap.cured_bar_basis')")
        ->not->toContain('format(curedSoapOutputBasisWeight')
        ->not->toContain('format(curedSoapResidualWaterWeight')
        ->not->toContain('numeric mt-3 text-xl')
        ->and($outputTab)
        ->toContain("__('workbench.output.soap.label_basis_help')")
        ->not->toContain('This view normalizes the selected acceptable ingredient list')
        ->not->toContain('11% residual water</span>')
        ->not->toContain('Dry soap output')
        ->not->toContain('Dry soap %')
        ->and($ingredientListPreview)
        ->toContain("__('workbench.output.lists.title')")
        ->toContain("__('workbench.output.lists.copy')")
        ->toContain("__('workbench.output.common.soap_percent')")
        ->not->toContain("__('workbench.output.soap.cured_basis')")
        ->and($presentationSection)
        ->toContain('get curedSoapIngredientRows()')
        ->toContain('percent_of_cured_basis')
        ->not->toContain('nonWaterTotalWeight')
        ->not->toContain('missingSoapMass');
});

it('organizes ingredient lists around generated and editable final outputs', function () {
    $source = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/ingredient-list-preview.blade.php'));

    expect($source)
        ->toContain("__('workbench.output.lists.title')")
        ->toContain("__('workbench.output.lists.help')")
        ->toContain("__('workbench.output.lists.inci')")
        ->toContain("__('workbench.output.lists.plain')")
        ->toContain("__('workbench.output.lists.final_inci')")
        ->toContain("__('workbench.output.lists.final_plain')")
        ->toContain("t('output.lists.as_added')")
        ->toContain("__('workbench.output.lists.use_generated')")
        ->not->toContain('Use as final')
        ->not->toContain('Generated from the selected ingredient-list variant')
        ->not->toContain('Cured soap basis')
        ->and(substr_count($source, 'workbench.output.lists.copy'))
        ->toBe(4);
});

it('keeps generated ingredient controls and final editors aligned in both lanes', function () {
    $source = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/ingredient-list-preview.blade.php'));

    expect($source)
        ->toContain('mt-5 grid items-stretch gap-5 xl:grid-cols-2')
        ->and(substr_count($source, '<div class="flex flex-col gap-5">'))
        ->toBe(2)
        ->and(substr_count($source, '<section class="sk-inset flex flex-1 flex-col px-5 py-4'))
        ->toBe(2)
        ->and(substr_count($source, 'mt-4 flex-1 rounded-lg bg-[var(--color-field)]'))
        ->toBe(2)
        ->and($source)
        ->toContain('@click="useGeneratedIngredientListAsFinal()" class="sk-btn sk-btn-outline"')
        ->toContain('@click="useGeneratedPlainIngredientListAsFinal()" class="sk-btn sk-btn-outline"')
        ->toContain('declarationRowsRequireAttention')
        ->toContain('aria-controls="declaration-details-body"')
        ->and(file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/restrictions-preview.blade.php')))
        ->toContain('restrictionsRequireAttention')
        ->toContain('aria-controls="restrictions-preview-body"')
        ->and(file_get_contents(resource_path('js/recipe-workbench/sections/presentation-section.js')))
        ->toContain('get declarationRowsRequireAttention()')
        ->toContain('get restrictionsRequireAttention()');
});

it('keeps the generated inci actions together beside compact helper text', function () {
    $source = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/ingredient-list-preview.blade.php'));

    expect($source)
        ->toContain('<div class="min-w-0 sm:max-w-64">')
        ->toContain('<div class="flex shrink-0 flex-nowrap items-center gap-2">');
});

it('keeps final ingredient list undo feedback accessible and persistent', function (): void {
    $source = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/ingredient-list-preview.blade.php'));
    $statusPosition = strpos($source, 'x-show="ingredientListUndo"');
    $gridPosition = strpos($source, 'mt-5 grid items-stretch gap-5 xl:grid-cols-2');

    expect($source)
        ->toContain('x-show="ingredientListUndo"')
        ->toContain('role="status"')
        ->toContain('aria-live="polite"')
        ->toContain('x-text="ingredientListUndo?.message"')
        ->toContain('<button type="button" @click="undoIngredientListChange()"')
        ->toContain("__('workbench.messages.undo')")
        ->toContain('@input="touchFinalIngredientList()"')
        ->toContain('@input="touchFinalPlainIngredientList()"')
        ->not->toContain('ingredientListUndoTimer')
        ->and($statusPosition)->toBeInt()
        ->and($gridPosition)->toBeInt()
        ->and($statusPosition)->toBeLessThan($gridPosition);
});

it('animates only the ingredient row that was just added', function () {
    $componentSource = file_get_contents(resource_path('js/recipe-workbench/component.js'));
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $postReaction = view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render();
    $cosmeticFormula = view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render();

    $combinedFormulaRows = implode("\n", [$reactionCore, $postReaction, $cosmeticFormula]);

    expect($componentSource)
        ->toContain('lastAddedIngredientRowId')
        ->toContain('animateAddedIngredientRow')
        ->toContain("this.highlightFormulaTarget(element, true, 'center')")
        ->toContain('this.highlightSoapPhase(targetPhase, false)')
        ->toContain('document.getElementById(`soap-phase-${phaseKey}`)')
        ->toContain('const renderedPhase = document.getElementById(`soap-phase-${phaseKey}`)')
        ->toContain('this.highlightFormulaTarget(renderedPhase, shouldScroll)')
        ->toContain("this.addIngredient(defaultOil, 'saponified_oils', false)")
        ->toContain('addIngredient(ingredient, requestedPhase = null, shouldAnimate = true)')
        ->toContain("matchMedia('(prefers-reduced-motion: reduce)')")
        ->toContain("behavior: this.prefersReducedMotion() ? 'auto' : 'smooth'")
        ->not->toContain("backgroundColor: 'transparent'")
        ->toContain("'ring-[color-mix(in_oklab,var(--color-accent)_55%,transparent)]'")
        ->toContain('}, 1200);')
        ->not->toContain('duration: 1600')
        ->not->toContain("'ring-[var(--color-accent)]'")
        ->not->toContain("transform: 'translateY(-6px) scale(0.992)'")
        ->not->toContain('opacity: 0.78')
        ->and($reactionCore)
        ->toContain('id="soap-phase-saponified_oils"')
        ->and($postReaction)
        ->toContain('id="soap-phase-additives"')
        ->toContain('id="soap-phase-fragrance"')
        ->and($combinedFormulaRows)
        ->toContain(':data-workbench-row-id="row.id"')
        ->toContain('animateAddedIngredientRow($el, row.id)')
        ->toContain('transition-[background-color,box-shadow] duration-300')
        ->not->toContain('motion-safe:will-change-transform');
});

it('highlights nested soap phases without a separated second outline', function () {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import { register } from 'node:module';
import { pathToFileURL } from 'node:url';

register(
    'data:text/javascript,' + encodeURIComponent(`
        export async function resolve(specifier, context, nextResolve) {
            if (specifier.startsWith('.') && !specifier.endsWith('.js')) {
                try {
                    return await nextResolve(specifier, context);
                } catch {
                    return nextResolve(specifier + '.js', context);
                }
            }

            return nextResolve(specifier, context);
        }
    `),
    pathToFileURL(`${process.cwd()}/`).href,
);

global.window = {
    location: { hash: '' },
    localStorage: { getItem: () => null, setItem: () => {} },
};
global.document = {
    addEventListener() {},
    removeEventListener() {},
};
global.setTimeout = () => 1;
Object.defineProperty(global, 'navigator', {
    value: { languages: ['en-US'], language: 'en-US', maxTouchPoints: 0 },
    configurable: true,
});

const { createRecipeWorkbench } = await import('./resources/js/recipe-workbench/component.js');
const workbench = createRecipeWorkbench({
    productFamily: { slug: 'soap' },
    numberLocaleOptions: { en_US: '1,234.56' },
});

const createElement = (initialClasses) => {
    const classes = new Set(initialClasses);
    const added = [];

    return {
        added,
        classList: {
            add(...nextClasses) {
                added.push(...nextClasses);
                nextClasses.forEach((className) => classes.add(className));
            },
            contains(className) {
                return classes.has(className);
            },
            remove(...removedClasses) {
                removedClasses.forEach((className) => classes.delete(className));
            },
        },
    };
};

const nestedPhase = createElement(['sk-inset']);
workbench.highlightFormulaTarget(nestedPhase, false);

assert.ok(nestedPhase.added.includes('border-[color-mix(in_oklab,var(--color-accent)_55%,transparent)]'));
assert.ok(!nestedPhase.added.includes('ring-2'));
assert.ok(!nestedPhase.added.includes('ring-offset-2'));

const cosmeticPhase = createElement(['border-y']);
cosmeticPhase.id = 'cosmetic-phase-a';
workbench.highlightFormulaTarget(cosmeticPhase, false);
assert.deepEqual(cosmeticPhase.added, ['sk-added-phase-highlight']);

const outerPhase = createElement(['sk-card']);
workbench.highlightFormulaTarget(outerPhase, false);

assert.ok(outerPhase.added.includes('ring-2'));
assert.ok(outerPhase.added.includes('ring-offset-2'));

window.matchMedia = () => ({ matches: false });
const row = createElement(['sk-formula-table-row', 'lg:bg-[var(--color-line)]']);
row.dataset = {};
row.scrollIntoView = () => {};
row.animate = () => { throw new Error('Row background animation hides the grid dividers'); };
workbench.lastAddedIngredientRowId = 'new-row';
workbench.animateAddedIngredientRow(row, 'new-row');
assert.ok(row.classList.contains('lg:bg-[var(--color-line)]'));
assert.ok(row.added.includes('sk-added-row-highlight'));
assert.ok(!row.added.includes('ring-2'));
assert.ok(!row.added.includes('ring-offset-2'));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toBe('');
});

it('highlights the cosmetic phase and reveals the added ingredient row', function () {
    $componentSource = file_get_contents(resource_path('js/recipe-workbench/component.js'));
    $cosmeticFormula = view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render();

    expect($cosmeticFormula)
        ->toContain(':id="`cosmetic-phase-${phase.key}`"')
        ->toContain(':data-cosmetic-phase-key="phase.key"')
        ->toContain(':data-workbench-row-id="row.id"')
        ->toContain('animateAddedIngredientRow($el, row.id)')
        ->toContain('flex w-full items-center justify-between gap-3')
        ->toContain('min-w-0 flex-1')
        ->and($componentSource)
        ->toContain('this.highlightCosmeticPhase(targetPhase, false)')
        ->toContain('document.getElementById(`cosmetic-phase-${phaseKey}`)')
        ->toContain('if (this.isCosmeticFormula) {')
        ->toContain("this.highlightFormulaTarget(element, true, 'center')")
        ->toContain('highlightFormulaTarget')
        ->toContain("behavior: this.prefersReducedMotion() ? 'auto' : 'smooth'");
});

it('focuses the newly added amount on eligible desktop rows without disturbing row scrolling', function (): void {
    $script = <<<'JS'
import assert from 'node:assert/strict';
import fs from 'node:fs';

const mediaMatches = new Map();
const timers = new Map();
let nextTimerId = 1;

globalThis.window = {
    location: { hash: '' },
    localStorage: { getItem: () => null, setItem: () => {} },
    matchMedia: (query) => ({ matches: mediaMatches.get(query) ?? false }),
};
globalThis.document = {
    addEventListener() {},
    removeEventListener() {},
    getElementById: () => null,
};
globalThis.setTimeout = (callback) => {
    const timerId = nextTimerId++;
    timers.set(timerId, callback);
    callback();

    return timerId;
};
globalThis.clearTimeout = (timerId) => timers.delete(timerId);
Object.defineProperty(globalThis, 'navigator', {
    value: { maxTouchPoints: 0 },
    configurable: true,
});

const resolveIngredientTargetPhase = (ingredient, requestedPhase = null) => requestedPhase
    ?? ingredient.available_phases?.[0]
    ?? null;

const source = fs
    .readFileSync('resources/js/recipe-workbench/component.js', 'utf8')
    .replace(/^import[\s\S]*?;\n/gm, '')
    .replace(/export function /g, 'function ');

eval(`${source}\nglobalThis.createCatalogSection = createCatalogSection;`);

const createWorkbench = ({ isCosmeticFormula = false } = {}) => {
    const workbench = {
        productFamilySlug: isCosmeticFormula ? 'cosmetic' : 'soap',
        isCosmeticFormula,
        ingredients: [],
        phaseItems: isCosmeticFormula
            ? { phase_a: [] }
            : { saponified_oils: [], lye_water: [], additives: [], fragrance: [] },
        phaseOrder: isCosmeticFormula
            ? [{ key: 'phase_a', name: 'Phase A' }]
            : [
                { key: 'saponified_oils', name: 'Saponified Oils' },
                { key: 'lye_water', name: 'Lye Water' },
                { key: 'additives', name: 'Additives' },
                { key: 'fragrance', name: 'Fragrance' },
            ],
        editMode: 'percentage',
        formulaItemLimit: null,
        formulaItemLimitMessage: '',
        lastAddedIngredientRowId: null,
        removedFormulaRowUndo: null,
        cosmeticFormulaRows() {
            return Object.values(this.phaseItems)
                .flatMap((rows) => Array.isArray(rows) ? rows : []);
        },
        resolveTargetPhase: (ingredient, requestedPhase = null) => requestedPhase
            ?? ingredient.available_phases?.[0]
            ?? null,
        lyeLiquidAdditionLimitReached: () => false,
        formulaItemLimitReached: () => false,
        t: (path) => path,
        highlightCalls: [],
        highlightSoapPhase(phaseKey, shouldScroll) {
            this.highlightCalls.push({ type: 'soap-phase', phaseKey, shouldScroll });
        },
        highlightCosmeticPhase(phaseKey, shouldScroll) {
            this.highlightCalls.push({ type: 'cosmetic-phase', phaseKey, shouldScroll });
        },
    };

    Object.defineProperties(
        workbench,
        Object.getOwnPropertyDescriptors(globalThis.createCatalogSection()),
    );

    workbench.highlightSoapPhase = (phaseKey, shouldScroll) => {
        workbench.highlightCalls.push({ type: 'soap-phase', phaseKey, shouldScroll });
    };
    workbench.highlightCosmeticPhase = (phaseKey, shouldScroll) => {
        workbench.highlightCalls.push({ type: 'cosmetic-phase', phaseKey, shouldScroll });
    };

    return workbench;
};

const createRowElement = (events, editMode, inputAvailable = true) => {
    const input = {
        focus(options) {
            events.push({ type: 'focus', options });
        },
        select() {
            events.push({ type: 'select' });
        },
    };

    return {
        dataset: {},
        classList: { add() {}, contains() { return false; }, remove() {} },
        scrollIntoView(options) {
            events.push({ type: 'scroll', options });
        },
        querySelector(selector) {
            events.push({ type: 'query', selector });

            return inputAvailable && selector === `[data-workbench-amount-input="${editMode}"]`
                ? input
                : null;
        },
    };
};

const setEligibleDesktop = () => {
    mediaMatches.set('(min-width: 1024px) and (hover: hover) and (pointer: fine)', true);
    mediaMatches.set('(any-pointer: coarse)', false);
    mediaMatches.set('(prefers-reduced-motion: reduce)', false);
    globalThis.navigator.maxTouchPoints = 0;
};

const exerciseAddedRow = ({ isCosmeticFormula, phase, mode, ingredientId }) => {
    const workbench = createWorkbench({ isCosmeticFormula });
    const events = [];

    workbench.editMode = mode;
    workbench.$nextTick = (callback) => {
        events.push({ type: 'next-tick' });
        callback();
    };
    setEligibleDesktop();

    const ingredient = {
        id: ingredientId,
        name: `Ingredient ${ingredientId}`,
        category: isCosmeticFormula ? 'cosmetic' : 'lipids',
        available_phases: [phase],
    };

    workbench.addIngredient(ingredient, phase, true);
    const row = workbench.phaseItems[phase][0];
    const rowElement = createRowElement(events, mode);
    workbench.animateAddedIngredientRow(rowElement, row.id);

    assert.equal(workbench.lastAddedIngredientRowId, row.id);
    assert.deepEqual(
        events.map(({ type }) => type),
        ['scroll', 'next-tick', 'query', 'focus', 'select'],
    );
    assert.deepEqual(
        events.filter(({ type }) => ['scroll', 'focus', 'select'].includes(type)).map(({ type }) => type),
        ['scroll', 'focus', 'select'],
    );
    assert.deepEqual(events.find(({ type }) => type === 'scroll')?.options, {
        behavior: 'smooth',
        block: 'center',
    });
    assert.deepEqual(events.find(({ type }) => type === 'focus')?.options, { preventScroll: true });
    assert.equal(events.find(({ type }) => type === 'query')?.selector, `[data-workbench-amount-input="${mode}"]`);

    if (isCosmeticFormula) {
        assert.deepEqual(workbench.highlightCalls, [{ type: 'cosmetic-phase', phaseKey: phase, shouldScroll: false }]);
    } else {
        assert.deepEqual(workbench.highlightCalls, [{ type: 'soap-phase', phaseKey: phase, shouldScroll: false }]);
    }
};

exerciseAddedRow({ isCosmeticFormula: false, phase: 'saponified_oils', mode: 'percentage', ingredientId: 1 });
exerciseAddedRow({ isCosmeticFormula: false, phase: 'additives', mode: 'weight', ingredientId: 2 });
exerciseAddedRow({ isCosmeticFormula: false, phase: 'fragrance', mode: 'percentage', ingredientId: 3 });
exerciseAddedRow({ isCosmeticFormula: true, phase: 'phase_a', mode: 'weight', ingredientId: 4 });

const excludedDeviceCases = [
    {
        name: 'wide coarse pointer',
        desktop: true,
        coarse: true,
        maxTouchPoints: 0,
    },
    {
        name: 'navigator touch points',
        desktop: true,
        coarse: false,
        maxTouchPoints: 1,
    },
    {
        name: 'narrow viewport',
        desktop: false,
        coarse: false,
        maxTouchPoints: 0,
    },
];

for (const device of excludedDeviceCases) {
    const workbench = createWorkbench();
    const events = [];
    const ingredient = {
        id: device.name,
        name: device.name,
        category: 'lipids',
        available_phases: ['additives'],
    };

    workbench.$nextTick = (callback) => callback();
    workbench.editMode = 'weight';
    mediaMatches.set('(min-width: 1024px) and (hover: hover) and (pointer: fine)', device.desktop);
    mediaMatches.set('(any-pointer: coarse)', device.coarse);
    mediaMatches.set('(prefers-reduced-motion: reduce)', false);
    globalThis.navigator.maxTouchPoints = device.maxTouchPoints;

    workbench.addIngredient(ingredient, 'additives', true);
    const row = workbench.phaseItems.additives[0];
    workbench.animateAddedIngredientRow(createRowElement(events, workbench.editMode), row.id);

    assert.equal(workbench.lastAddedIngredientRowId, row.id, device.name);
    assert.deepEqual(
        events.filter(({ type }) => ['scroll', 'focus', 'select'].includes(type)).map(({ type }) => type),
        ['scroll'],
        device.name,
    );
    assert.deepEqual(workbench.highlightCalls, [{ type: 'soap-phase', phaseKey: 'additives', shouldScroll: false }], device.name);
}

setEligibleDesktop();
const staleWorkbench = createWorkbench();
staleWorkbench.$nextTick = (callback) => callback();
staleWorkbench.addIngredient({ id: 5, name: 'First', available_phases: ['additives'] }, 'additives', true);
const staleRow = staleWorkbench.phaseItems.additives[0];
staleWorkbench.addIngredient({ id: 6, name: 'Second', available_phases: ['additives'] }, 'additives', true);
const currentRow = staleWorkbench.phaseItems.additives[1];
const staleEvents = [];
staleWorkbench.animateAddedIngredientRow(createRowElement(staleEvents, 'percentage'), staleRow.id);
assert.equal(staleWorkbench.lastAddedIngredientRowId, currentRow.id);
assert.deepEqual(staleEvents, []);

const branchWorkbench = createWorkbench();
branchWorkbench.ingredients = [
    { id: 7, name: 'Automatic liquid', category: 'botanical', available_phases: ['lye_water'] },
];
branchWorkbench.addLyeLiquidIngredient(7);
assert.equal(branchWorkbench.lastAddedIngredientRowId, null);

branchWorkbench.addIngredient({ id: 8, name: 'Default addition', available_phases: ['additives'] }, 'additives', false);
assert.equal(branchWorkbench.lastAddedIngredientRowId, null);

branchWorkbench.addIngredient({ id: 9, name: 'Added once', available_phases: ['additives'] }, 'additives', true);
const successfulMarker = branchWorkbench.lastAddedIngredientRowId;
branchWorkbench.addIngredient({ id: 9, name: 'Added once', available_phases: ['additives'] }, 'additives', true);
assert.equal(branchWorkbench.lastAddedIngredientRowId, successfulMarker);

branchWorkbench.resolveTargetPhase = () => null;
branchWorkbench.addIngredient({ id: 10, name: 'Unresolved', available_phases: ['additives'] }, 'additives', true);
assert.equal(branchWorkbench.lastAddedIngredientRowId, successfulMarker);

branchWorkbench.resolveTargetPhase = (ingredient, requestedPhase = null) => requestedPhase
    ?? ingredient.available_phases?.[0]
    ?? null;
branchWorkbench.formulaItemLimitReached = () => true;
branchWorkbench.addIngredient({ id: 11, name: 'Over limit', available_phases: ['additives'] }, 'additives', true);
assert.equal(branchWorkbench.lastAddedIngredientRowId, successfulMarker);

branchWorkbench.formulaItemLimitReached = () => false;
branchWorkbench.lyeLiquidAdditionLimitReached = () => true;
branchWorkbench.ingredients.push({ id: 12, name: 'Lye limit', available_phases: ['lye_water'] });
branchWorkbench.addLyeLiquidIngredient(12);
assert.equal(branchWorkbench.lastAddedIngredientRowId, successfulMarker);
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $postReaction = view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render();
    $cosmeticFormula = view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render();

    expect(substr_count($reactionCore, 'data-workbench-amount-input="percentage"'))
        ->toBe(1)
        ->and(substr_count($reactionCore, 'data-workbench-amount-input="weight"'))
        ->toBe(1)
        ->and(substr_count($postReaction, 'data-workbench-amount-input="percentage"'))
        ->toBe(2)
        ->and(substr_count($postReaction, 'data-workbench-amount-input="weight"'))
        ->toBe(2)
        ->and(substr_count($cosmeticFormula, 'data-workbench-amount-input="percentage"'))
        ->toBe(1)
        ->and(substr_count($cosmeticFormula, 'data-workbench-amount-input="weight"'))
        ->toBe(1);
});

it('keeps the cosmetic phase picker visible outside the scrollable ingredient list', function () {
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser', [
        'isCosmeticWorkbench' => true,
    ])->render();

    expect($ingredientBrowser)
        ->toContain('aria-label="Choose a phase for this ingredient"')
        ->toContain('<template x-teleport="body">')
        ->toContain('position: fixed')
        ->toContain('max-h-[min(16rem,calc(100vh-2rem))]')
        ->toContain('@scroll.window="if (open) { reposition(); }"');
});

it('uses a restrained semantic color system for live workbench diagnostics', function () {
    $themeSource = file_get_contents(resource_path('css/shared/soapkraft.css'));
    $appStylesSource = file_get_contents(resource_path('css/app.css'));
    $formulaSectionSource = file_get_contents(resource_path('js/recipe-workbench/sections/formula-section.js'));
    $presentationSectionSource = file_get_contents(resource_path('js/recipe-workbench/sections/presentation-section.js'));
    $bottomActionBar = view('livewire.dashboard.partials.recipe-workbench.formula-bottom-action-bar')->render();
    $formulaSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings')->render();
    $costingTab = view('livewire.dashboard.partials.recipe-workbench.costing-tab')->render();
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();
    $ingredientBrowserSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/ingredient-browser.blade.php'));
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $postReaction = view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render();
    $cosmeticFormula = view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render();
    $formulaAnalysis = view('livewire.dashboard.partials.recipe-workbench.formula-analysis')->render();
    $fattyAcidProfile = view('livewire.dashboard.partials.recipe-workbench.fatty-acid-profile')->render();
    $appShellSource = file_get_contents(resource_path('views/layouts/app-shell.blade.php'));

    $formulaDropTargets = implode("\n", [$reactionCore, $postReaction, $cosmeticFormula]);
    $formulaSettingsWithoutMassInputs = preg_replace(
        '/<input aria-labelledby="setting-(?:base-weight|water-mode)"[^>]+>/',
        '',
        $formulaSettings,
    ) ?? $formulaSettings;

    expect($themeSource)
        ->toContain('--color-surface: oklch(96.4% 0.016 128)')
        ->toContain('--color-panel: oklch(98.5% 0.006 85)')
        ->toContain('--color-forest: oklch(25.5% 0.030 155)')
        ->toContain('--color-accent: oklch(55.5% 0.112 55)')
        ->toContain('--color-on-accent: oklch(98.0% 0.006 85)')
        ->toContain('--color-active: oklch(43.0% 0.066 146)')
        ->toContain('--color-active-soft: oklch(93.2% 0.030 145)')
        ->toContain('--color-active-strong: oklch(31.5% 0.064 146)')
        ->toContain('--color-on-active: oklch(98.0% 0.007 145)')
        ->toContain('--color-control: oklch(99.1% 0.004 88)')
        ->toContain('--color-success: oklch(49.0% 0.085 166)')
        ->toContain('--color-chemistry: oklch(55.5% 0.146 49)')
        ->toContain('--color-info: oklch(50.0% 0.075 230)')
        ->toContain('.sk-tone-chemistry')
        ->toContain('.sk-tone-catalog')
        ->toContain('--sk-tone: var(--color-active)')
        ->toContain('.sk-tone-materials')
        ->toContain('.sk-tone-analysis')
        ->toContain('.sk-tone-summary')
        ->toContain('--color-sidebar-active')
        ->not->toContain('margin: 0.75rem 0.75rem 0')
        ->not->toContain('border-radius: 0.85rem')
        ->not->toContain('border: 1px solid color-mix(in oklab, var(--sk-tone) 22%, var(--color-line))')
        ->and($appStylesSource)
        ->toContain('.sk-card')
        ->not->toContain(".sk-card {\n        border: 1px solid transparent")
        ->toContain('.sk-inset')
        ->toContain('border: 1px solid color-mix(in oklab, var(--color-line) 88%, var(--color-ink) 4%)')
        ->toContain('.sk-workbench :is(button:not([role="tab"]), input:not([type="range"]):not(.sk-formula-title-control):not(.sk-field-control):not(.sk-input), select, textarea, a, summary):focus-visible')
        ->toContain('box-shadow: inset 0 0 0 1px')
        ->toContain('outline: none !important')
        ->not->toContain('outline-style: solid !important')
        ->and($formulaSectionSource)
        ->toContain("tone: hasResolvedWeights ? 'chemistry' : 'warning'")
        ->toContain("tone: 'info'")
        ->and($presentationSectionSource)
        ->toContain("return 'border-[var(--color-line)] bg-white';")
        ->not->toContain("return 'border-[var(--color-line-strong)] bg-[var(--color-accent-soft)]';")
        ->and($bottomActionBar)
        ->toContain("card.tone === 'chemistry'")
        ->toContain("card.tone === 'info'")
        ->toContain('sk-status-surface')
        ->toContain("'sk-tone-chemistry': card.tone === 'chemistry'")
        ->toContain("'sk-tone-info': card.tone === 'info'")
        ->toContain('text-[var(--color-on-accent)]')
        ->and($formulaSettings)
        ->toContain('sk-tone-chemistry')
        ->toContain('sk-tone-info')
        ->toContain('border-[var(--color-active)] bg-[var(--color-active)] text-[var(--color-on-active)]')
        ->toContain('border-[var(--color-field-outline)] bg-transparent text-[var(--color-ink-strong)]')
        ->and($formulaSettingsWithoutMassInputs)
        ->not->toContain('focus:outline-2')
        ->not->toContain('outline-[var(--color-field-outline)]')
        ->and($costingTab)
        ->toContain('bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm')
        ->toContain('bg-[var(--color-control)] text-[var(--color-ink-soft)]')
        ->not->toContain('bg-[var(--color-accent-soft)]')
        ->not->toContain('focus:outline-2')
        ->not->toContain('outline-[var(--color-field-outline)]')
        ->and($ingredientBrowser)
        ->toContain('sk-section-header-reference')
        ->toContain('sk-tone-catalog')
        ->toContain('text-[var(--color-on-accent)]')
        ->and($ingredientBrowserSource)
        ->toContain('class="grid size-9 place-items-center rounded-full bg-[var(--color-accent)]')
        ->toContain('hover:bg-[var(--color-active-soft)]')
        ->and($reactionCore)
        ->toContain('sk-section-header-formula')
        ->toContain('sk-tone-chemistry')
        ->and($postReaction)
        ->toContain('sk-section-header-formula')
        ->toContain('sk-tone-summary')
        ->not->toContain('sk-tone-materials')
        ->not->toContain('bg-[var(--color-accent-soft)]')
        ->and($formulaDropTargets)
        ->toContain('bg-[var(--color-active-soft)]')
        ->toContain('text-[var(--color-active-strong)]')
        ->not->toContain("isDropTarget('saponified_oils') ? 'bg-[var(--color-accent-soft)]")
        ->not->toContain("isDropTarget('additives') ? 'bg-[var(--color-accent-soft)]")
        ->not->toContain("isDropTarget(phase.key) ? 'bg-[var(--color-accent-soft)]")
        ->and($formulaAnalysis)
        ->toContain('sk-section-header-reference')
        ->toContain('sk-tone-analysis')
        ->toContain('border-b-[var(--color-ink-strong)] text-[var(--color-ink-strong)]')
        ->and($fattyAcidProfile)
        ->toContain('sk-section-header-reference')
        ->toContain('sk-tone-analysis')
        ->toContain('bg-[var(--color-active)]')
        ->and($appShellSource)
        ->toContain('bg-[var(--color-sidebar-active)]')
        ->toContain('border-l-[var(--color-sidebar-active-text)]');

    expect($appStylesSource)
        ->toContain('[data-user-shell]')
        ->toContain('--color-surface: oklch(97.2% 0.010 128)')
        ->toContain('--color-panel: oklch(98.8% 0.006 85)')
        ->toContain('--color-accent: oklch(53.0% 0.090 55)')
        ->toContain('[data-user-shell] .sk-tone-analysis')
        ->toContain('.sk-card > .sk-section-header:first-child')
        ->toContain('border-top-left-radius: inherit')
        ->toContain('border-top-right-radius: inherit')
        ->toContain('.sk-formula-table-row.sk-added-row-highlight > div')
        ->toContain('.sk-formula-table-row.sk-added-row-highlight .sk-formula-table-cell')
        ->toContain('.sk-formula-table-row.sk-added-row-highlight [data-workbench-amount-input]')
        ->not->toContain('outline-offset: -3px')
        ->toContain('--sk-tone: var(--color-active)')
        ->and($appShellSource)
        ->toContain('<body data-user-shell');
});

it('reserves copper for actions and uses botanical green for selected user states', function () {
    $ingredientsIndexSource = file_get_contents(resource_path('views/livewire/dashboard/ingredients-index.blade.php'));
    $mediaLibrarySource = file_get_contents(resource_path('views/livewire/dashboard/media-library-index.blade.php'));
    $accountSource = file_get_contents(resource_path('views/account/show.blade.php'));
    $appStylesSource = file_get_contents(resource_path('css/app.css'));

    expect($ingredientsIndexSource)
        ->toContain("'border-[var(--color-active)] bg-[var(--color-active-soft)] text-[var(--color-active-strong)]'")
        ->toContain("\$isMine ? 'bg-[var(--color-active-soft)] text-[var(--color-active-strong)]'")
        ->not->toContain("\$isMine ? 'bg-[var(--color-warning-soft)] text-[var(--color-warning-strong)]'")
        ->and($mediaLibrarySource)
        ->toContain("\$usageFilter === \$value ? 'border-[var(--color-active)] bg-[var(--color-active-soft)] text-[var(--color-active-strong)]'")
        ->not->toContain("\$usageFilter === \$value ? 'border-[var(--color-accent)] bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)]'")
        ->and($accountSource)
        ->toContain('rounded-lg border border-[var(--color-line)] bg-[var(--color-panel)] p-4')
        ->toContain('h-full rounded-full bg-[var(--color-active)]')
        ->not->toContain('h-full rounded-full bg-[var(--color-accent)]')
        ->and($appStylesSource)
        ->toContain(".sk-btn-outline {\n        border: 1px solid var(--color-line);\n        background: var(--color-panel);");
});

it('collapses formula settings into a setup summary for soap and cosmetic benches', function () {
    $componentSource = file_get_contents(resource_path('js/recipe-workbench/component.js'));
    $formulaSectionSource = file_get_contents(resource_path('js/recipe-workbench/sections/formula-section.js'));
    $bottomActionBar = view('livewire.dashboard.partials.recipe-workbench.formula-bottom-action-bar')->render();
    $soapSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings')->render();
    $cosmeticSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings', [
        'isCosmeticWorkbench' => true,
    ])->render();

    expect($componentSource)
        ->toContain('isFormulaSettingsOpen: initialDraft === null')
        ->toContain('toggleFormulaSettings()')
        ->and($formulaSectionSource)
        ->toContain('get formulaSetupSummaryCards()')
        ->toContain('get lyeTypeSummaryLabel()')
        ->toContain('get waterModeSummaryLabel()')
        ->toContain('if (this.canPersist)')
        ->toContain("label: this.t('settings.production_output')")
        ->toContain("this.t('settings.manufactured_ingredient')")
        ->toContain("this.t('settings.finished_product')")
        ->and($soapSettings)
        ->toContain('Formula settings')
        ->toContain('data-formula-output-type')
        ->toContain('class="sk-card px-4 py-3"')
        ->toContain('class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between"')
        ->toContain('transition-[grid-template-rows,visibility] duration-300 ease-out motion-reduce:transition-none')
        ->not->toContain('x-transition.opacity')
        ->toContain('data-formula-settings-primary')
        ->toContain('data-formula-settings-context')
        ->toContain('mt-1.5 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5 text-xs')
        ->toContain('min-w-0 break-words whitespace-normal')
        ->toContain('filter(card => ! card.context)')
        ->toContain('filter(card => card.context)')
        ->toContain('class="mt-4"')
        ->toContain("'sk-tone-summary': card.tone === 'neutral'")
        ->not->toContain('Calculation assumptions')
        ->not->toContain('sk-section-header')
        ->not->toContain('class="p-5"')
        ->and($soapSettings)
        ->toContain('formulaSetupSummaryCards')
        ->toContain(":class=\"! isFormulaSettingsOpen ? 'grid-rows-[1fr]' : 'grid-rows-[0fr] invisible'\"")
        ->toContain(':aria-expanded="isFormulaSettingsOpen.toString()"')
        ->toContain('aria-controls="formula-settings-panel"')
        ->toContain(":class=\"isFormulaSettingsOpen ? 'grid-rows-[1fr]' : 'grid-rows-[0fr] invisible'\"")
        ->toContain("t('settings.edit')")
        ->and($cosmeticSettings)
        ->toContain('Formula settings')
        ->toContain('data-formula-output-type')
        ->toContain('formulaSetupSummaryCards')
        ->toContain(":class=\"! isFormulaSettingsOpen ? 'grid-rows-[1fr]' : 'grid-rows-[0fr] invisible'\"")
        ->toContain(":class=\"isFormulaSettingsOpen ? 'grid-rows-[1fr]' : 'grid-rows-[0fr] invisible'\"")
        ->and($bottomActionBar)
        ->toContain('Formula status')
        ->toContain('Formula save bar')
        ->toContain('class="flex flex-wrap items-center gap-2 lg:flex-nowrap"')
        ->toContain('class="flex min-w-0 flex-1 gap-2 overflow-x-auto')
        ->toContain('id="formula-bottom-diagnostics-details"')
        ->toContain('class="grid gap-2 sm:grid-cols-2 xl:grid-cols-5"')
        ->not->toContain('lg:sticky')
        ->not->toContain('lg:top-4')
        ->not->toContain('class="sk-card p-3');
});

it('surfaces one shared entry mode control immediately above each formula ledger', function (): void {
    $formulaTabSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php'));
    $formulaSettingsSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php'));
    $formulaSectionSource = file_get_contents(resource_path('js/recipe-workbench/sections/formula-section.js'));
    $translationSource = file_get_contents(lang_path('en/workbench.php'));
    $soapFormulaTab = view('livewire.dashboard.partials.recipe-workbench.formula-tab')->render();
    $cosmeticFormulaTab = view('livewire.dashboard.partials.recipe-workbench.formula-tab', [
        'isCosmeticWorkbench' => true,
    ])->render();
    $entryModeTogglePath = resource_path('views/components/recipe-workbench/entry-mode-toggle.blade.php');

    $entryModeToggle = file_exists($entryModeTogglePath)
        ? file_get_contents($entryModeTogglePath)
        : '';

    $entryModeComponentPosition = strpos($formulaTabSource, '<x-recipe-workbench.entry-mode-toggle />');
    $formulaBranchPosition = strpos($formulaTabSource, '@if ($isCosmeticWorkbench)');

    expect(substr_count($formulaTabSource, '<x-recipe-workbench.entry-mode-toggle />'))
        ->toBe(1)
        ->and($entryModeComponentPosition)
        ->toBeLessThan($formulaBranchPosition)
        ->and($formulaSettingsSource)
        ->not->toContain('id="setting-entry-mode"')
        ->not->toContain('id="setting-entry-mode-soap"')
        ->and($formulaSectionSource)
        ->toContain("id: 'formula-entry'")
        ->toContain("value: this.editMode === 'weight' ? this.t('common.weight')")
        ->toContain('get entryModeHelperText()')
        ->toContain("this.t('settings.cosmetic_weight_entry_help')")
        ->toContain("this.t('settings.soap_weight_entry_help')")
        ->toContain("this.t('settings.cosmetic_percentage_entry_help')")
        ->toContain("this.t('settings.soap_percentage_entry_help')")
        ->and($translationSource)
        ->toContain("'cosmetic_percentage_entry_help' => 'Set formula shares; quantities follow the total batch.'")
        ->toContain("'cosmetic_weight_entry_help' => 'Set ingredient quantities; total batch and percentages recalculate.'")
        ->toContain("'soap_percentage_entry_label' => '% oils'")
        ->toContain("'soap_percentage_entry_help' => 'Set oil and addition shares; quantities follow total oils.'")
        ->toContain("'soap_weight_entry_help' => 'Oil quantities recalculate total oils and % oils; additions remain based on total oils.'")
        ->and(substr_count($soapFormulaTab, 'id="formula-entry-mode-heading"'))
        ->toBe(1)
        ->and(substr_count($cosmeticFormulaTab, 'id="formula-entry-mode-heading"'))
        ->toBe(1)
        ->and($entryModeTogglePath)
        ->toBeFile()
        ->and($entryModeToggle)
        ->toContain('<section class="flex flex-col gap-2 rounded-lg bg-[var(--color-field-muted)] px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between" aria-labelledby="formula-entry-mode-heading">')
        ->toContain('aria-labelledby="formula-entry-mode-heading"')
        ->toContain('role="radiogroup"')
        ->toContain(':aria-checked="editMode === \'percentage\'"')
        ->toContain(':aria-checked="editMode === \'weight\'"')
        ->toContain('x-text="entryModeHelperText"')
        ->and(substr_count($entryModeToggle, 'min-h-11'))
        ->toBe(2)
        ->and($entryModeToggle)
        ->toContain("'bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm'")
        ->toContain("'bg-[var(--color-control)] text-[var(--color-ink-soft)] hover:bg-[var(--color-panel)]'");
});

it('uses the open setup tone surfaces for collapsed and sticky formula summaries', function () {
    $formulaSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings')->render();
    $bottomActionBar = view('livewire.dashboard.partials.recipe-workbench.formula-bottom-action-bar')->render();
    $sharedStylesSource = file_get_contents(resource_path('css/shared/soapkraft.css'));

    expect($formulaSettings)
        ->toContain('sk-status-surface')
        ->toContain("'sk-tone-chemistry': card.tone === 'chemistry'")
        ->toContain("'sk-tone-info': card.tone === 'info'")
        ->toContain("'sk-tone-summary': card.tone === 'neutral'")
        ->not->toContain("'bg-[var(--color-chemistry-soft)] text-[var(--color-chemistry-strong)]': card.tone === 'chemistry'")
        ->not->toContain("'bg-[var(--color-info-soft)] text-[var(--color-info-strong)]': card.tone === 'info'")
        ->and($bottomActionBar)
        ->toContain('sk-status-surface')
        ->toContain("'sk-tone-success': card.tone === 'success'")
        ->toContain("'sk-tone-warning': card.tone === 'warning'")
        ->toContain("'sk-tone-danger': card.tone === 'danger'")
        ->not->toContain("'bg-[var(--color-success-soft)] text-[var(--color-success-strong)]': card.tone === 'success'")
        ->not->toContain("'bg-[var(--color-danger-soft)] text-[var(--color-danger-strong)]': card.tone === 'danger'")
        ->and($sharedStylesSource)
        ->toContain('.sk-status-surface')
        ->toContain('background: color-mix(in oklab, var(--sk-tone-soft) 34%, var(--color-panel) 66%);');
});

it('uses category fallback tiles for both soap and cosmetic ingredient browsers', function (): void {
    $stylesheet = file_get_contents(resource_path('css/app.css'));
    preg_match('/\.sk-ingredient-image-tile\s*\{([^}]*)\}/', $stylesheet, $tileRule);

    expect($stylesheet)
        ->toContain('.sk-ingredient-image-tile')
        ->toContain('.sk-ingredient-image-tile.is-fallback')
        ->and($tileRule[1] ?? null)
        ->not->toContain('box-shadow:')
        ->and($tileRule[1] ?? null)
        ->toContain('border: 1px solid var(--color-line)')
        ->toContain('border-radius: 0.5rem');

    foreach ([false, true] as $isCosmeticWorkbench) {
        $rendered = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser', [
            'isCosmeticWorkbench' => $isCosmeticWorkbench,
        ])->render();

        expect($rendered)
            ->toContain('ingredient.fallback_image_url')
            ->toContain("ingredient.image_url && !imageFailed ? '' : 'is-fallback'")
            ->toContain('x-on:error="imageFailed = true"')
            ->not->toContain('ingredientCategoryCode(ingredient)');
    }
});
