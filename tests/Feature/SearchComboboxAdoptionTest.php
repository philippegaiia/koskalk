<?php

use Illuminate\Support\Facades\Blade;
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

it('keeps shared search combobox actions on accessible SVG icons', function (): void {
    $component = file_get_contents(resource_path('views/components/search-combobox.blade.php'));
    $rendered = Blade::render('<x-search-combobox id="icon-contract-search" label="Search" :options="[]" />');

    expect($component)
        ->toContain('<x-action-icon name="close" />')
        ->toContain('<x-action-icon name="chevron-down" />')
        ->toContain('@click="clear(); $nextTick(() => document.getElementById(@js($id))?.focus())"')
        ->toContain('@click="open = ! open; activeIndex = -1"')
        ->not->toContain('>×</span>')
        ->not->toContain('>⌄</span>');

    expect($rendered)
        ->toContain('aria-label="Clear search"')
        ->toContain('aria-label="Toggle search options"')
        ->toContain('stroke="currentColor"')
        ->toContain('focusable="false"');
});

it('gives the product category combobox room without clipping or a nested focus line', function () {
    $formulaSettings = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php'));
    $component = file_get_contents(resource_path('js/recipe-workbench/component.js'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($formulaSettings)
        ->toContain('data-product-category-setting')
        ->toContain('class="mt-3 max-w-3xl"')
        ->toContain("formulaSettingsOverflow ? 'overflow-visible' : 'overflow-hidden'")
        ->toContain('min-w-0 gap-4 lg:grid-cols-2 xl:grid-cols-4')
        ->not->toContain('lg:grid-cols-2 xl:grid-cols-5')
        ->and($component)
        ->toContain('formulaSettingsOverflow: initialDraft === null')
        ->toContain('this.formulaSettingsOverflowTimer = setTimeout')
        ->and($styles)
        ->toContain('input:not([type="range"]):not(.sk-formula-title-control):not(.sk-field-control)')
        ->toContain('.sk-combobox-control:focus-within')
        ->toContain('border-color: var(--color-active);');
});

it('uses the shared search combobox for the main formula category filter', function () {
    $ingredientBrowser = file_get_contents(resource_path('views/livewire/dashboard/partials/recipe-workbench/ingredient-browser.blade.php'));

    expect($ingredientBrowser)
        ->toContain('<x-search-combobox')
        ->toContain('id="ingredient-category-search"')
        ->toContain('x-effect="replaceOptions(categoryOptions.map')
        ->toContain('x-on:search-combobox-selected="activeCategory = String($event.detail.id)"')
        ->toContain("x-on:search-combobox-cleared=\"activeCategory = 'all'; syncSelection('all')\"")
        ->toContain('overflow-visible sk-card')
        ->toContain('relative z-20 space-y-3')
        ->toContain('overflow-y-auto')
        ->not->toContain('<select x-model="activeCategory"')
        ->toContain('x-model="search"')
        ->toContain('filteredIngredients');
});

it('searches category options by subcategory while keeping main category values selectable', function () {
    $script = <<<'JS'
import fs from 'node:fs';
import { createSearchCombobox } from './resources/js/search-combobox.js';

const catalogSource = fs.readFileSync('resources/js/recipe-workbench/catalog.js', 'utf8')
    .replace(/import \{[\s\S]*?\} from '\.\/utils';\s*/, '')
    .replaceAll('export function ', 'function ');
const humanizeKey = (value) => `${value ?? ''}`
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (character) => character.toUpperCase());

eval(`${catalogSource}\nglobalThis.categoryOptions = categoryOptions;`);

const options = categoryOptions([
    {
        category: 'lipids',
        category_label: 'Lipids',
        subcategory: 'carrier_oils',
        subcategory_label: 'Carrier oils',
    },
    {
        category: 'lipids',
        category_label: 'Lipids',
        subcategory: 'butters',
        subcategory_label: 'Butters',
    },
    {
        category: 'botanicals_extracts',
        category_label: 'Botanicals and extracts',
        subcategory: 'plant_powders',
        subcategory_label: 'Plant powders',
    },
]);
const state = createSearchCombobox({
    id: 'ingredient-category-search',
    options: options.map((option) => ({
        id: option.value,
        label: option.label,
        description: option.description,
        searchText: option.searchText,
    })),
});

state.$dispatch = () => {};
state.query = 'butters';
state.handleInput();

console.log(JSON.stringify({
    values: options.map((option) => option.value),
    lipids: options.find((option) => option.value === 'lipids'),
    filteredValues: state.filteredOptions.map((option) => option.id),
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['values'])->toBe(['all', 'botanicals_extracts', 'lipids'])
        ->and($payload['lipids']['count'])->toBe(2)
        ->and($payload['lipids']['description'])->toBe('Butters · Carrier oils')
        ->and($payload['lipids']['searchText'])->toContain('butters')
        ->and($payload['filteredValues'])->toBe(['lipids']);
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
