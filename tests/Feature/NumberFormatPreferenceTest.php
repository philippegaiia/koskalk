<?php

use App\Livewire\Dashboard\SettingsIndex;
use App\Models\ProductFamily;
use App\Models\User;
use App\Services\RecipeWorkbenchViewDataBuilder;
use App\Support\NumberLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

it('defines number formats separately from interface languages', function () {
    expect(Schema::hasColumn('users', 'number_locale'))->toBeTrue()
        ->and(NumberLocale::codes())->toBe([
            'en_US',
            'en_GB',
            'fr_FR',
            'es_ES',
            'de_DE',
            'it_IT',
        ])
        ->and(NumberLocale::isSupported('en_GB'))->toBeTrue()
        ->and(NumberLocale::isSupported('pt_BR'))->toBeFalse();
});

it('lets a registered user save a number format preference', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(SettingsIndex::class)
        ->assertSet('numberLocale', 'en_US')
        ->set('numberLocale', 'fr_FR')
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertSet('profileStatus', 'success');

    expect($user->refresh()->number_locale)->toBe('fr_FR');
});

it('rejects an unsupported registered user number format', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test(SettingsIndex::class)
        ->set('numberLocale', 'xx_XX')
        ->call('saveProfile')
        ->assertHasErrors(['numberLocale']);

    expect($user->refresh()->number_locale)->toBeNull();
});

it('passes the resolved number format and choices to the shared workbench', function () {
    $user = User::factory()->create(['number_locale' => 'de_DE']);
    $productFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);

    $workbench = app(RecipeWorkbenchViewDataBuilder::class)->build($productFamily, null, $user);

    expect($workbench['numberLocale'])->toBe('de_DE')
        ->and($workbench['numberLocaleOptions'])->toHaveKeys(NumberLocale::codes())
        ->and($workbench['numberLocaleOptions']['en_GB'])->toContain('1,234.56');
});

it('accepts comma and dot input while formatting with the selected locale', function () {
    $script = <<<'JS'
import {
  formatDecimalInput,
  formatNumber,
  parseDecimalInput,
  resolveNumberLocale,
} from './resources/js/recipe-workbench/number-format.js';

console.log(JSON.stringify({
  comma: parseDecimalInput('12,5'),
  dot: parseDecimalInput('12.5'),
  negative: parseDecimalInput('-0,75'),
  groupedFrench: parseDecimalInput('1 234,56'),
  frenchFixed: formatNumber(12.5, 2, 'fr_FR'),
  englishFixed: formatNumber(12.5, 2, 'en_US'),
  frenchFlexible: formatDecimalInput('12.500', 'fr_FR'),
  britishBrowser: resolveNumberLocale(null, ['en_US', 'en_GB', 'fr_FR'], ['en-GB']),
  frenchBrowser: resolveNumberLocale(null, ['en_US', 'en_GB', 'fr_FR'], ['fr-CA']),
}));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($result)->toMatchArray([
        'comma' => 12.5,
        'dot' => 12.5,
        'negative' => -0.75,
        'groupedFrench' => 1234.56,
        'frenchFixed' => '12,50',
        'englishFixed' => '12.50',
        'frenchFlexible' => '12,5',
        'britishBrowser' => 'en_GB',
        'frenchBrowser' => 'fr_FR',
    ]);
});

it('serializes localized numeric strings as canonical numbers', function () {
    $script = <<<'JS'
import fs from 'node:fs';
import { parseDecimalInput } from './resources/js/recipe-workbench/number-format.js';

const source = fs
  .readFileSync('resources/js/recipe-workbench/payload.js', 'utf8')
  .replace(/^import[\s\S]*?;\n/gm, '')
  .replace(/export function /g, 'function ');

const normalizedIfraProductCategoryId = (value) => value;
const rowWeight = () => 0;
const number = (value) => parseDecimalInput(value);
const nonNegativeNumber = (value) => Math.max(0, number(value));

eval(`${source}\nglobalThis.serializeDraft = serializeDraft;`);

const payload = globalThis.serializeDraft({
  formulaName: 'Localized formula',
  oilUnit: 'g',
  oilWeight: '1000,5',
  waterValue: '38,5',
  superfat: '5,25',
  kohPurity: '90,5',
  dualKohPercentage: '40,5',
  phaseOrder: [],
  phaseItems: {},
  packagingPlanRows: [],
});

console.log(JSON.stringify(payload));
JS;

    $process = Process::fromShellCommandline(
        'node --input-type=module -e '.escapeshellarg($script),
        base_path(),
    );

    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $payload = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'oil_weight' => 1000.5,
        'water_value' => 38.5,
        'superfat' => 5.25,
        'koh_purity_percentage' => 90.5,
        'dual_lye_koh_percentage' => 40.5,
    ]);
});
