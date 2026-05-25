<?php

it('starts soap users in the carrier oil catalog while cosmetics stay broad', function () {
    $componentSource = file_get_contents(resource_path('js/recipe-workbench/component.js'));

    expect($componentSource)
        ->toContain("activeCategory: isCosmeticFormula ? 'all' : 'carrier_oil'");
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
        ->toContain('IFRA context')
        ->and($cosmeticSettings)
        ->toContain('Label &amp; compliance')
        ->toContain('x-show="isComplianceSettingsOpen"')
        ->toContain('IFRA context');

    expect(strpos($soapSettings, 'setting-exposure-soap'))
        ->toBeLessThan(strpos($soapSettings, 'Label &amp; compliance'));
});

it('keeps workbench navigation and reference actions visually quiet', function () {
    $header = view('livewire.dashboard.partials.recipe-workbench.header')->render();
    $navigation = view('livewire.dashboard.partials.recipe-workbench.navigation')->render();

    expect($navigation)
        ->not->toContain('border-t-2')
        ->and($header)
        ->toContain('More formula actions')
        ->toContain('<details')
        ->not->toContain('Open reference formula</a>');
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
        ->toContain('aria-haspopup="dialog"')
        ->toContain(':aria-expanded="open.toString()"')
        ->toContain('@keydown.escape.window="open = false"');

    expect($header)
        ->toContain('@click.outside="open = false"')
        ->toContain('@keydown.escape.prevent.stop="open = false"');
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

it('keeps formula table lines at a balanced fourteen pixel vertical padding', function () {
    $tablePartials = [
        view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render(),
        view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render(),
        view('livewire.dashboard.partials.recipe-workbench.cosmetic-formula')->render(),
    ];

    $combinedFormulaTableMarkup = implode("\n", $tablePartials);

    expect($combinedFormulaTableMarkup)
        ->toContain('py-3.5')
        ->toContain('lg:py-3.5')
        ->not->toContain('px-4 py-4 text-center');
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
        ->toContain('lg:grid-cols-[19rem_minmax(0,1fr)]')
        ->toContain('class="space-y-4 lg:sticky lg:top-4 lg:self-start"')
        ->toContain('class="hidden lg:block"')
        ->toContain('class="lg:hidden"')
        ->not->toContain('lg:max-h-[calc(100vh-7rem)]')
        ->not->toContain('lg:overflow-y-auto')
        ->not->toContain('lg:pr-1')
        ->not->toContain('class="hidden xl:block"')
        ->not->toContain('class="xl:hidden"');

    expect($ingredientBrowser)
        ->toContain('Select ingredients')
        ->toContain('mt-2 text-lg font-semibold')
        ->not->toContain('Filtered by category')
        ->not->toContain('mt-2 text-xl font-semibold')
        ->toContain('max-h-[18rem]')
        ->toContain('md:max-h-[22rem]')
        ->toContain('lg:max-h-[24rem]')
        ->toContain('xl:max-h-[600px]')
        ->not->toContain('Fatty acid profile');

    expect(strpos($formulaTabSource, 'class="lg:hidden"'))
        ->toBeGreaterThan(strpos($formulaTabSource, 'post-reaction'))
        ->toBeLessThan(strpos($formulaTabSource, 'formula-analysis'));
});

it('keeps the ingredient browser column lean on desktop displays', function () {
    $formulaTabSource = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-tab.blade.php'));

    expect($formulaTabSource)
        ->toContain('lg:grid-cols-[19rem_minmax(0,1fr)]')
        ->toContain('2xl:grid-cols-[22rem_minmax(0,1fr)]')
        ->not->toContain('class="grid gap-4 lg:grid-cols-[22rem_minmax(0,1fr)]');
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
        ->not->toContain('text-xl font-semibold text-[var(--color-ink-strong)]');
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
        ->toContain('Zero quantity')
        ->toContain('Formula status')
        ->toContain('Show details')
        ->toContain('Hide details')
        ->toContain('formulaDiagnosticSummaryCards')
        ->toContain('toggleFormulaDiagnostics()')
        ->toContain('aria-controls="formula-bottom-diagnostics-details"')
        ->toContain('x-show="isFormulaDiagnosticsOpen"')
        ->toContain(':aria-expanded="isFormulaDiagnosticsOpen.toString()"')
        ->toContain('saveDraft()')
        ->toContain('requestOfficialRecipeSave()')
        ->toContain('Save draft')
        ->toContain('Save as reference')
        ->not->toContain('SAP')
        ->not->toContain('Missing KOH SAP')
        ->and($formulaSectionSource)
        ->toContain('get formulaDiagnosticCards()')
        ->toContain('get formulaDiagnosticSummaryCards()')
        ->toContain('zeroQuantityRows()')
        ->toContain("label: 'Save state'")
        ->toContain("Couldn't save")
        ->toContain("'Saved'")
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
        ->toContain('aria-label="Choose phase for ingredient"')
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
    $ingredientBrowser = view('livewire.dashboard.partials.recipe-workbench.ingredient-browser')->render();
    $reactionCore = view('livewire.dashboard.partials.recipe-workbench.reaction-core')->render();
    $postReaction = view('livewire.dashboard.partials.recipe-workbench.post-reaction')->render();
    $formulaAnalysis = view('livewire.dashboard.partials.recipe-workbench.formula-analysis')->render();
    $fattyAcidProfile = view('livewire.dashboard.partials.recipe-workbench.fatty-acid-profile')->render();

    expect($themeSource)
        ->toContain('--color-forest: oklch(27.9% 0.039 150)')
        ->toContain('--color-accent: oklch(53.0% 0.075 145)')
        ->toContain('--color-success: oklch(49.0% 0.085 166)')
        ->toContain('--color-chemistry: oklch(55.5% 0.146 49)')
        ->toContain('--color-info: oklch(50.0% 0.075 230)')
        ->toContain('.sk-tone-chemistry')
        ->toContain('.sk-tone-catalog')
        ->toContain('.sk-tone-materials')
        ->toContain('.sk-tone-analysis')
        ->not->toContain('margin: 0.75rem 0.75rem 0')
        ->not->toContain('border-radius: 0.85rem')
        ->not->toContain('border: 1px solid color-mix(in oklab, var(--sk-tone) 22%, var(--color-line))')
        ->and($appStylesSource)
        ->toContain('.sk-card')
        ->not->toContain(".sk-card {\n        border: 1px solid transparent")
        ->toContain('.sk-inset')
        ->toContain('border: 1px solid color-mix(in oklab, var(--color-line) 88%, var(--color-ink) 4%)')
        ->and($formulaSectionSource)
        ->toContain("tone: hasResolvedWeights ? 'chemistry' : 'warning'")
        ->toContain("tone: 'info'")
        ->and($presentationSectionSource)
        ->toContain("return 'border-[var(--color-line)] bg-white';")
        ->not->toContain("return 'border-[var(--color-line-strong)] bg-[var(--color-accent-soft)]';")
        ->and($bottomActionBar)
        ->toContain("card.tone === 'chemistry'")
        ->toContain("card.tone === 'info'")
        ->toContain('bg-[var(--color-chemistry-soft)]')
        ->toContain('bg-[var(--color-info-soft)]')
        ->and($formulaSettings)
        ->toContain('sk-tone-chemistry')
        ->toContain('sk-tone-info')
        ->and($ingredientBrowser)
        ->toContain('sk-tone-catalog')
        ->and($reactionCore)
        ->toContain('sk-tone-chemistry')
        ->and($postReaction)
        ->toContain('sk-tone-materials')
        ->and($formulaAnalysis)
        ->toContain('sk-tone-analysis')
        ->and($fattyAcidProfile)
        ->toContain('sk-tone-analysis');
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
        ->toContain('Formula setup')
        ->toContain('class="sk-card px-5 py-4"')
        ->toContain('class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"')
        ->toContain('class="mt-2 flex flex-wrap gap-2"')
        ->toContain('class="mt-4"')
        ->toContain("'bg-[var(--color-field-muted)] text-[var(--color-ink-soft)]': card.tone === 'neutral'")
        ->not->toContain('Calculation assumptions')
        ->not->toContain('sk-section-header')
        ->not->toContain('class="p-5"')
        ->and($soapSettings)
        ->toContain('formulaSetupSummaryCards')
        ->toContain('x-show="! isFormulaSettingsOpen"')
        ->toContain(':aria-expanded="isFormulaSettingsOpen.toString()"')
        ->toContain('aria-controls="formula-settings-panel"')
        ->toContain('x-show="isFormulaSettingsOpen"')
        ->toContain('Edit settings')
        ->and($cosmeticSettings)
        ->toContain('Formula setup')
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
