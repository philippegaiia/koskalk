<?php

use Symfony\Component\Process\Process;

it('uses the shared search combobox for large user-facing catalogs', function () {
    $packaging = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/packaging-tab.blade.php'));
    $costing = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/costing-tab.blade.php'));
    $formulaSettings = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php'));
    $ifraCategoryModal = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/ifra-category-modal.blade.php'));
    $settings = file_get_contents(resource_path('views/livewire/dashboard/settings-index.blade.php'));

    expect($packaging)
        ->toContain('<x-search-combobox')
        ->toContain('id="packaging-catalog-search"')
        ->not->toContain('packagingCatalogSelectOpen')
        ->and($costing)
        ->toContain('id="costing-currency-search"')
        ->and($formulaSettings)
        ->toContain('<x-search-combobox')
        ->toContain('id="product-type-search"')
        ->toContain('x-on:search-combobox-selected="changeProductType($event.detail.id)"')
        ->not->toContain('x-model="productTypeId"')
        ->not->toContain('id="cosmetic-ifra-context-search"')
        ->not->toContain('id="soap-ifra-context-search"')
        ->and($ifraCategoryModal)
        ->toContain('ifra-category-modal-heading')
        ->not->toContain('<x-search-combobox')
        ->and($settings)
        ->toContain('id="workspace-currency-search"');
});

it('gives the product category combobox room without clipping or a nested focus line', function () {
    $formulaSettings = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($formulaSettings)
        ->toContain('data-product-category-setting')
        ->toContain('class="mt-3 max-w-3xl"')
        ->toContain("isFormulaSettingsOpen ? 'overflow-visible' : 'overflow-hidden'")
        ->toContain('lg:grid-cols-2 xl:grid-cols-4')
        ->not->toContain('lg:grid-cols-2 xl:grid-cols-5')
        ->and($styles)
        ->toContain('input:not([type="range"]):not(.sk-formula-title-control):not(.sk-field-control)')
        ->toContain('.sk-combobox-control:focus-within')
        ->toContain('border-color: var(--color-active);');
});

it('keeps the main formula ingredient browser unchanged', function () {
    $ingredientBrowser = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/ingredient-browser.blade.php'));

    expect($ingredientBrowser)
        ->not->toContain('<x-search-combobox')
        ->toContain('x-model="search"')
        ->toContain('filteredIngredients');
});

it('supports string identifiers and replacing options in the shared search combobox', function () {
    $source = file_get_contents(resource_path('js/search-combobox.js'));

    expect($source)
        ->toContain('sameId(')
        ->toContain('replaceOptions(')
        ->not->toContain('Number(option.id)');
});

it('emits search terms for bounded server-side catalogs', function () {
    $script = <<<'JS'
import { createSearchCombobox } from './resources/js/search-combobox.js';

const events = [];
const state = createSearchCombobox({
    id: 'supplier',
    options: [],
    retainSelection: true,
    allowEmpty: false,
});

state.$dispatch = (name, detail) => events.push({ name, detail });
state.query = 'olive';
state.handleInput();

console.log(JSON.stringify(events));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $events = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($events)->toContain([
        'name' => 'search-combobox-query',
        'detail' => ['comboboxId' => 'supplier', 'query' => 'olive'],
    ]);
});

it('selects string-valued options and refreshes a dynamic catalog', function () {
    $script = <<<'JS'
import { createSearchCombobox } from './resources/js/search-combobox.js';

const events = [];
const state = createSearchCombobox({
    id: 'currency',
    options: [
        { id: 'EUR', label: 'EUR — Euro' },
        { id: 'USD', label: 'USD — US Dollar' },
    ],
    selectedId: 'EUR',
    retainSelection: true,
    allowEmpty: false,
});

state.$dispatch = (name, detail) => events.push({ name, detail });
state.init();
state.query = 'dollar';
state.handleInput();
const filteredIds = state.filteredOptions.map((option) => option.id);
state.selectOption(state.filteredOptions[0]);
state.replaceOptions([
    ...state.options,
    { id: 'GBP', label: 'GBP — Pound Sterling' },
]);

console.log(JSON.stringify({
    filteredIds,
    selectedId: state.selectedId,
    query: state.query,
    optionIds: state.options.map((option) => option.id),
    event: events.at(-1),
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)
        ->toMatchArray([
            'filteredIds' => ['USD'],
            'selectedId' => 'USD',
            'query' => 'USD — US Dollar',
            'optionIds' => ['EUR', 'USD', 'GBP'],
        ])
        ->and($payload['event']['name'])->toBe('search-combobox-selected')
        ->and($payload['event']['detail']['id'])->toBe('USD');
});
