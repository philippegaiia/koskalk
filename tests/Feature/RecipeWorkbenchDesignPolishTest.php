<?php

it('starts soap users in the carrier oil catalog and keeps the visible selector synced', function () {
    $componentSource = file_get_contents(resource_path('js/recipe-workbench/component.js'));
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();

    expect($componentSource)
        ->toContain("activeCategory: isCosmeticFormula ? 'all' : 'carrier_oil'")
        ->and($ingredientBrowser)
        ->toContain(':selected="option.value === activeCategory"');
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
        ->toContain('x-show="isComplianceSettingsOpen"')
        ->toContain('IFRA category')
        ->and($cosmeticSettings)
        ->toContain('Label &amp; compliance')
        ->toContain('x-show="isComplianceSettingsOpen"')
        ->toContain('IFRA category');

    expect(strpos($soapSettings, 'setting-exposure-soap'))
        ->toBeLessThan(strpos($soapSettings, 'Label &amp; compliance'));
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
        ->toContain('min-w-max')
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
        ->toContain('mx-auto max-w-7xl')
        ->not->toContain('max-w-[90rem]')
        ->not->toContain('max-w-[104rem]')
        ->and($bottomActionBarSource)
        ->toContain('mx-auto max-w-7xl')
        ->not->toContain('max-w-[90rem]')
        ->not->toContain('max-w-[104rem]')
        ->and($recipeWorkbenchPageSource)
        ->toContain('mx-auto mb-4 max-w-7xl')
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
        ->toContain('mt-4 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between')
        ->toContain('sk-formula-title-control min-w-0 flex-1')
        ->toContain('sk-formula-actions')
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
        ->toContain('class="mt-4 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between"')
        ->toContain('x-show="productTypeName || saveMessage || calculationPreviewMessage"')
        ->toContain('class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs')
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

it('presents batch totals as one compact neutral summary grid', function () {
    $postReaction = view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render();
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $appStylesSource = file_get_contents(resource_path('css/app.css'));
    $sharedStylesSource = file_get_contents(resource_path('css/shared/soapkraft.css'));

    expect($postReaction)
        ->toContain('sk-phase-craft sk-tone-summary')
        ->not->toContain('sk-phase-craft sk-tone-materials')
        ->toContain('sk-card sk-tone-summary overflow-hidden')
        ->toContain('sk-section-header border-b px-5 py-4')
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

it('rounds water mode controls like the other formula setup surfaces', function () {
    $formulaSettings = view('livewire.dashboard.partials.recipe-workbench.formula-settings')->render();

    expect(substr_count($formulaSettings, 'rounded-[1rem] px-4 py-2.5 text-left text-xs font-medium transition'))
        ->toBe(3);
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
        ->toContain(':aria-expanded="soapQualitiesExpanded.toString()"', false)
        ->toContain('Bar &amp; cure', false)
        ->toContain('Lather &amp; feel', false)
        ->toContain('inline-flex items-center gap-2')
        ->toContain('rounded-lg border border-b-2 border-[var(--color-line)] bg-white/35 px-3.5 py-2')
        ->toContain("'border-b-[var(--color-accent)] text-[var(--color-accent)]'")
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

it('keeps formula table lines compact with ten pixel vertical padding', function () {
    $appStylesSource = file_get_contents(resource_path('css/app.css'));
    $tablePartials = [
        view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render(),
        view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render(),
        view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render(),
    ];

    $combinedFormulaTableMarkup = implode("\n", $tablePartials);

    expect($appStylesSource)
        ->toContain('.sk-formula-table-y')
        ->toContain('padding-block: 10px')
        ->toContain('.sk-formula-table-row')
        ->toContain('font-size: 14px')
        ->toContain('.sk-formula-table-handle-cell')
        ->toContain('align-items: center')
        ->and($combinedFormulaTableMarkup)
        ->toContain('sk-formula-table-y')
        ->toContain('sk-formula-table-row')
        ->toContain('sk-formula-table-cell')
        ->toContain('sk-formula-table-handle-cell')
        ->toContain('px-2.5 py-2.5 text-sm sk-formula-table-row')
        ->toContain('bg-white py-2.5 sk-formula-table-cell')
        ->toContain('bg-white py-2.5 sk-formula-table-handle-cell')
        ->not->toContain('This block is derived from the saponified oils, lye type, water mode, and superfat.')
        ->not->toContain('py-3.5')
        ->not->toContain('lg:py-3.5')
        ->not->toContain('lg:py-2.5')
        ->not->toContain('p-2.5 text-sm transition')
        ->not->toContain('px-4 py-4 text-center');
});

it('centers costing table row contents beside price inputs', function () {
    $costingTab = view('livewire.dashboard.partials.recipe-workbench.costing-tab')->render();

    expect($costingTab)
        ->toContain('flex items-center bg-white px-4 py-3 text-[var(--color-ink-soft)]" x-text="row.phaseLabel"')
        ->toContain('numeric flex items-center bg-white px-4 py-3 text-[var(--color-ink-soft)]" x-text="`${format(row.percentage, 2)}%`"')
        ->toContain('flex items-center bg-white px-3 py-3')
        ->toContain('numeric flex items-center bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(lineCostForRow(row), 2)}`"')
        ->not->toContain('numeric bg-white px-4 py-3 font-medium text-[var(--color-ink-strong)]" x-text="`${costingCurrency} ${format(lineCostForRow(row), 2)}`"');
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

it('keeps the ingredient browser rail sticky on large screens and moves soap fatty acids below the table on mobile', function () {
    $formulaTabSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php'));
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();

    expect($formulaTabSource)
        ->toContain('@5xl/workbench:grid-cols-[19rem_minmax(0,1fr)]')
        ->toContain('class="space-y-4 @5xl/workbench:sticky @5xl/workbench:top-4 @5xl/workbench:self-start"')
        ->toContain('class="hidden @5xl/workbench:block"')
        ->toContain('class="@5xl/workbench:hidden"')
        ->not->toContain('lg:max-h-[calc(100vh-7rem)]')
        ->not->toContain('lg:overflow-y-auto')
        ->not->toContain('lg:pr-1')
        ->not->toContain('class="hidden xl:block"')
        ->not->toContain('class="xl:hidden"');

    expect($ingredientBrowser)
        ->toContain('Add ingredients')
        ->toContain('text-lg font-semibold')
        ->not->toContain('Filtered by category')
        ->not->toContain('mt-2 text-xl font-semibold')
        ->toContain('max-h-[18rem]')
        ->toContain('md:max-h-[22rem]')
        ->toContain('lg:max-h-[24rem]')
        ->toContain('xl:max-h-[600px]')
        ->not->toContain('Fatty acid profile');

    expect(strpos($formulaTabSource, 'class="@5xl/workbench:hidden"'))
        ->toBeGreaterThan(strpos($formulaTabSource, 'post-reaction'))
        ->toBeLessThan(strpos($formulaTabSource, 'formula-analysis'));
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
        ->toContain('size-10 shrink-0')
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

it('keeps ingredient browser filters visible and pill shaped while focused', function () {
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();
    $appStylesSource = file_get_contents(resource_path('css/app.css'));
    $genericWorkbenchFocusPosition = strpos($appStylesSource, '.sk-workbench :is(button:not([role="tab"]), input:not([type="range"]):not(.sk-formula-title-control), select, textarea, a, summary):focus-visible');
    $ingredientFilterFocusPosition = strrpos($appStylesSource, '.sk-workbench .sk-ingredient-filter-control:focus-visible');

    expect($ingredientBrowser)
        ->toContain('sk-ingredient-filter-control w-full px-4 py-3 text-sm')
        ->and(substr_count($ingredientBrowser, 'sk-ingredient-filter-control'))
        ->toBe(2)
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
        '/\\.sk-workbench :is\\(button:not\\(\\[role="tab"\\]\\), input:not\\(\\[type="range"\\]\\):not\\(\\.sk-formula-title-control\\), select, textarea, a, summary\\):focus-visible \\{(?<rule>.*?)\\n\\}/s',
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
        ->toContain('.sk-workbench :is(button:not([role="tab"]), input:not([type="range"]):not(.sk-formula-title-control), select, textarea, a, summary):focus-visible')
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
        ->toContain('pb-28')
        ->and($bottomActionBar)
        ->toContain('Formula save bar')
        ->toContain('fixed bottom-0 left-0 right-0')
        ->toContain('z-30')
        ->toContain('lg:left-[var(--app-sidebar-width,0rem)]')
        ->toContain('bg-[color-mix(in_oklab,var(--color-panel)_82%,transparent)]')
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
        ->toContain('x-show="isFormulaDiagnosticsOpen"')
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

it('stacks production figure tables beside soap output and gives cosmetics one descending ingredient table', function () {
    $outputTab = view('livewire.dashboard.partials.recipe-workbench.output-tab')->render();
    $cosmeticOutputTab = view('livewire.dashboard.partials.recipe-workbench.output-tab', [
        'isCosmeticWorkbench' => true,
    ])->render();
    $presentationSectionSource = file_get_contents(resource_path('js/recipe-workbench/sections/presentation-section.js'));

    expect($outputTab)
        ->toContain('Production tables')
        ->toContain('lg:grid-cols-[minmax(0,7fr)_minmax(18rem,3fr)]')
        ->toContain('sm:grid-cols-3 lg:grid-cols-1')
        ->toContain('Batch ingredients')
        ->toContain('Cured soap composition')
        ->toContain('batchIngredientRows')
        ->toContain('drySoapIngredientRows')
        ->toContain('Formula %')
        ->toContain('Dry soap %')
        ->toContain('Stage')
        ->toContain('What to weigh into the batch before saponification, including lye and water.')
        ->not->toContain('Integrated ingredients')
        ->not->toContain('Mise en oeuvre')
        ->not->toContain('Ingredient basis')
        ->not->toContain('incorporatedIngredientRows')
        ->not->toContain('lg:grid-cols-2')
        ->and(substr_count($outputTab, 'Cured soap composition'))
        ->toBe(1)
        ->and(strpos($outputTab, 'Production tables'))
        ->toBeLessThan(strpos($outputTab, 'Dry soap output'))
        ->and(strpos($outputTab, 'Cured soap composition'))
        ->toBeLessThan(strpos($outputTab, 'Restrictions'))
        ->and($cosmeticOutputTab)
        ->toContain('Ingredient output')
        ->toContain('Ingredients sorted from highest to lowest formula share.')
        ->toContain('cosmeticOutputIngredientRows')
        ->toContain('% formula')
        ->toContain('Full formula')
        ->toContain('Add ingredients to build the cosmetic output list.')
        ->not->toContain('Production tables')
        ->not->toContain('Batch ingredients')
        ->and($presentationSectionSource)
        ->toContain('get batchIngredientRows()')
        ->toContain('get cosmeticOutputIngredientRows()')
        ->toContain('return right.percentage - left.percentage')
        ->toContain('get cosmeticOutputIngredientTotalWeight()')
        ->toContain('get cosmeticOutputIngredientTotalPercent()')
        ->toContain('this.lyeSummaryCards')
        ->toContain("stage: 'Lye solution'");
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
        ->toContain("this.addIngredient(defaultOil, 'saponified_oils', false)")
        ->toContain('addIngredient(ingredient, requestedPhase = null, shouldAnimate = true)')
        ->toContain("matchMedia('(prefers-reduced-motion: reduce)')")
        ->toContain("behavior: this.prefersReducedMotion() ? 'auto' : 'smooth'")
        ->and($combinedFormulaRows)
        ->toContain(':data-workbench-row-id="row.id"')
        ->toContain('animateAddedIngredientRow($el, row.id)')
        ->toContain('motion-safe:will-change-transform');
});

it('highlights cosmetic destination phases and keeps row inspectors right aligned', function () {
    $componentSource = file_get_contents(resource_path('js/recipe-workbench/component.js'));
    $cosmeticFormula = view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render();

    expect($cosmeticFormula)
        ->toContain(':id="`cosmetic-phase-${phase.key}`"')
        ->toContain(':data-cosmetic-phase-key="phase.key"')
        ->toContain('flex w-full items-center justify-between gap-3')
        ->toContain('min-w-0 flex-1')
        ->and($componentSource)
        ->toContain('highlightCosmeticPhase(targetPhase)')
        ->toContain('highlightFormulaTarget')
        ->toContain('document.getElementById(`cosmetic-phase-${phaseKey}`)')
        ->toContain("behavior: this.prefersReducedMotion() ? 'auto' : 'smooth'");
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
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $postReaction = view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render();
    $cosmeticFormula = view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render();
    $formulaAnalysis = view('livewire.dashboard.partials.recipe-workbench.formula-analysis')->render();
    $fattyAcidProfile = view('livewire.dashboard.partials.recipe-workbench.fatty-acid-profile')->render();
    $appShellSource = file_get_contents(resource_path('views/layouts/app-shell.blade.php'));

    $formulaDropTargets = implode("\n", [$reactionCore, $postReaction, $cosmeticFormula]);

    expect($themeSource)
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
        ->toContain('--color-sidebar-active')
        ->not->toContain('margin: 0.75rem 0.75rem 0')
        ->not->toContain('border-radius: 0.85rem')
        ->not->toContain('border: 1px solid color-mix(in oklab, var(--sk-tone) 22%, var(--color-line))')
        ->and($appStylesSource)
        ->toContain('.sk-card')
        ->not->toContain(".sk-card {\n        border: 1px solid transparent")
        ->toContain('.sk-inset')
        ->toContain('border: 1px solid color-mix(in oklab, var(--color-line) 88%, var(--color-ink) 4%)')
        ->toContain('.sk-workbench :is(button:not([role="tab"]), input:not([type="range"]):not(.sk-formula-title-control), select, textarea, a, summary):focus-visible')
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
        ->toContain('bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm')
        ->toContain('bg-[var(--color-control)] text-[var(--color-ink-soft)]')
        ->not->toContain('focus:outline-2')
        ->not->toContain('outline-[var(--color-field-outline)]')
        ->and($costingTab)
        ->toContain('bg-[var(--color-active)] text-[var(--color-on-active)] shadow-sm')
        ->toContain('bg-[var(--color-control)] text-[var(--color-ink-soft)]')
        ->not->toContain('focus:outline-2')
        ->not->toContain('outline-[var(--color-field-outline)]')
        ->and($ingredientBrowser)
        ->toContain('sk-tone-catalog')
        ->toContain('text-[var(--color-on-accent)]')
        ->not->toContain('focus-visible:outline-2')
        ->and($reactionCore)
        ->toContain('sk-tone-chemistry')
        ->and($postReaction)
        ->toContain('sk-tone-summary')
        ->not->toContain('sk-tone-materials')
        ->and($formulaDropTargets)
        ->toContain('bg-[var(--color-active-soft)]')
        ->toContain('text-[var(--color-active-strong)]')
        ->not->toContain("isDropTarget('saponified_oils') ? 'bg-[var(--color-accent-soft)]")
        ->not->toContain("isDropTarget('additives') ? 'bg-[var(--color-accent-soft)]")
        ->not->toContain("isDropTarget(phase.key) ? 'bg-[var(--color-accent-soft)]")
        ->and($formulaAnalysis)
        ->toContain('sk-tone-analysis')
        ->and($fattyAcidProfile)
        ->toContain('sk-tone-analysis')
        ->and($appShellSource)
        ->toContain('bg-[var(--color-sidebar-active)]')
        ->toContain('ring-[var(--color-sidebar-active-ring)]');
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
        ->and($soapSettings)
        ->toContain('Formula settings')
        ->toContain('class="sk-card px-5 py-4"')
        ->toContain('class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"')
        ->toContain('class="mt-2 flex flex-wrap gap-2"')
        ->toContain('class="mt-4"')
        ->toContain("'sk-tone-summary': card.tone === 'neutral'")
        ->not->toContain('Calculation assumptions')
        ->not->toContain('sk-section-header')
        ->not->toContain('class="p-5"')
        ->and($soapSettings)
        ->toContain('formulaSetupSummaryCards')
        ->toContain('x-show="! isFormulaSettingsOpen"')
        ->toContain(':aria-expanded="isFormulaSettingsOpen.toString()"')
        ->toContain('aria-controls="formula-settings-panel"')
        ->toContain('x-show="isFormulaSettingsOpen"')
        ->toContain("t('settings.edit')")
        ->and($cosmeticSettings)
        ->toContain('Formula settings')
        ->toContain('formulaSetupSummaryCards')
        ->toContain('x-show="! isFormulaSettingsOpen"')
        ->toContain('x-show="isFormulaSettingsOpen"')
        ->and($bottomActionBar)
        ->toContain('Formula status')
        ->toContain('Formula save bar')
        ->toContain('class="flex flex-wrap items-center gap-2 lg:flex-nowrap"')
        ->toContain('class="flex min-w-0 flex-1 gap-2 overflow-x-auto')
        ->toContain('id="formula-bottom-diagnostics-details"')
        ->toContain('class="mb-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-5"')
        ->not->toContain('lg:sticky')
        ->not->toContain('lg:top-4')
        ->not->toContain('class="sk-card p-3');
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
