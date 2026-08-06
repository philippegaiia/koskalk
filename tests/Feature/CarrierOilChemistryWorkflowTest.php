<?php

use App\IngredientCategory;
use App\Models\FattyAcid;
use App\Models\Ingredient;
use App\Models\IngredientFattyAcid;
use App\Models\IngredientSapProfile;
use Database\Seeders\CarrierOilChemistrySeeder;
use Database\Seeders\FattyAcidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->chemistryFixtureDirectory = sys_get_temp_dir().'/koskalk-carrier-oil-chemistry-'.bin2hex(random_bytes(8));

    mkdir($this->chemistryFixtureDirectory, 0777, true);
});

afterEach(function () {
    collect(glob($this->chemistryFixtureDirectory.'/*') ?: [])->each(fn (string $path) => unlink($path));

    if (is_dir($this->chemistryFixtureDirectory)) {
        rmdir($this->chemistryFixtureDirectory);
    }
});

it('syncs curated carrier oil chemistry onto existing catalog ingredients', function () {
    $this->seed(FattyAcidSeeder::class);

    $ingredient = Ingredient::factory()->create([
        'source_key' => 'OB100',
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive oil',
        'is_potentially_saponifiable' => false,
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.151,
        'source_notes' => 'Old chemistry',
    ]);

    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $ingredient->id,
        'fatty_acid_id' => FattyAcid::query()->where('key', 'oleic')->firstOrFail()->id,
        'percentage' => 55,
    ]);

    $fixturePath = writeCarrierOilChemistryFixture($this->chemistryFixtureDirectory, [
        [
            'source_key' => 'OB100',
            'koh_sap_value' => 0.188,
            'iodine_value' => 86.4,
            'ins_value' => 102.8,
            'source_notes' => 'Curated baseline chemistry.',
            'fatty_acids' => [
                'palmitic' => 12.0,
                'oleic' => 68.5,
                'linoleic' => 10.2,
            ],
        ],
    ]);

    config()->set('catalog-imports.carrier_oil_chemistry.path', $fixturePath);

    $this->seed(CarrierOilChemistrySeeder::class);

    $freshIngredient = $ingredient->fresh(['sapProfile', 'fattyAcidEntries.fattyAcid']);
    $profile = $freshIngredient->fattyAcidEntries
        ->mapWithKeys(fn (IngredientFattyAcid $entry): array => [$entry->fattyAcid->key => (float) $entry->percentage])
        ->sortKeys()
        ->all();

    expect($freshIngredient->is_potentially_saponifiable)->toBeTrue()
        ->and((float) $freshIngredient->sapProfile->koh_sap_value)->toBe(0.188)
        ->and((float) $freshIngredient->sapProfile->iodine_value)->toBe(86.4)
        ->and((float) $freshIngredient->sapProfile->ins_value)->toBe(102.8)
        ->and($freshIngredient->sapProfile->source_notes)->toBe('Curated baseline chemistry.')
        ->and($freshIngredient->fattyAcidEntries)->toHaveCount(3)
        ->and($profile)->toBe([
            'linoleic' => 10.2,
            'oleic' => 68.5,
            'palmitic' => 12.0,
        ]);
});

it('fails fast when chemistry rows do not match an existing carrier oil ingredient', function () {
    $this->seed(FattyAcidSeeder::class);

    $fixturePath = writeCarrierOilChemistryFixture($this->chemistryFixtureDirectory, [
        [
            'source_key' => 'OB404',
            'koh_sap_value' => 0.188,
            'fatty_acids' => [
                'oleic' => 70,
            ],
        ],
    ]);

    config()->set('catalog-imports.carrier_oil_chemistry.path', $fixturePath);

    expect(fn () => $this->seed(CarrierOilChemistrySeeder::class))
        ->toThrow(RuntimeException::class, 'does not match any existing ingredient');
});

it('reports carrier oils that are still missing soap chemistry', function () {
    $this->seed(FattyAcidSeeder::class);

    $oleic = FattyAcid::query()->where('key', 'oleic')->firstOrFail();

    $completeOil = Ingredient::factory()->create([
        'source_key' => 'OB-COMPLETE',
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Complete oil',
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $completeOil->id,
        'koh_sap_value' => 0.188,
    ]);

    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $completeOil->id,
        'fatty_acid_id' => $oleic->id,
        'percentage' => 65,
    ]);

    $missingSapOil = Ingredient::factory()->create([
        'source_key' => 'OB-MISS-SAP',
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Needs sap',
    ]);

    IngredientFattyAcid::factory()->create([
        'ingredient_id' => $missingSapOil->id,
        'fatty_acid_id' => $oleic->id,
        'percentage' => 72,
    ]);

    $missingFattyAcidOil = Ingredient::factory()->create([
        'source_key' => 'OB-MISS-FATTY',
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Needs fatty acids',
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $missingFattyAcidOil->id,
        'koh_sap_value' => 0.201,
    ]);

    Ingredient::factory()->create([
        'source_key' => 'ADD-IGNORE',
        'category' => IngredientCategory::Additive,
        'display_name' => 'Plain additive',
    ]);

    $exitCode = Artisan::call('catalog:report-missing-carrier-oil-chemistry', [
        '--json' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('"source_key": "OB-MISS-FATTY"')
        ->and($output)->toContain('"source_key": "OB-MISS-SAP"')
        ->and($output)->toContain('"missing_sap": true')
        ->and($output)->toContain('"missing_fatty_acids": true')
        ->and($output)->not->toContain('OB-COMPLETE')
        ->and($output)->not->toContain('ADD-IGNORE');
});

it('diffs carrier oils from the common name csv header when columns are reordered', function () {
    Ingredient::factory()->create([
        'source_key' => 'OB-OLIVE',
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive oil',
    ]);

    $csvPath = $this->chemistryFixtureDirectory.'/carrier-oils.csv';
    file_put_contents($csvPath, implode("\n", [
        'row_id,common_name,notes',
        '1,Olive oil,already imported',
        '2,Coconut oil,missing',
    ]));

    $exitCode = Artisan::call('catalog:diff-carrier-oils', [
        '--csv' => $csvPath,
        '--format' => 'json',
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('"Olive oil"')
        ->and($output)->toContain('"Coconut oil"')
        ->and($output)->not->toContain('"1"')
        ->and($output)->not->toContain('"2"');
});

it('imports explicit mendrulandia inci names without using the latin lookup fallback', function () {
    $this->seed(FattyAcidSeeder::class);

    $ingredient = Ingredient::factory()->create([
        'source_key' => 'OB-ALMOND',
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Almond Oil',
        'inci_name' => null,
        'soap_inci_naoh_name' => null,
        'soap_inci_koh_name' => null,
    ]);

    $exitCode = Artisan::call('catalog:import-carrier-oil-chemistry');

    $freshIngredient = $ingredient->fresh(['sapProfile', 'fattyAcidEntries']);

    expect($exitCode)->toBe(0)
        ->and($freshIngredient->inci_name)->toBe('Prunus Amygdalus Dulcis (Sweet Almond) Oil')
        ->and($freshIngredient->soap_inci_naoh_name)->toBeNull()
        ->and($freshIngredient->soap_inci_koh_name)->toBeNull()
        ->and((float) $freshIngredient->sapProfile->koh_sap_value)->toBe(0.188)
        ->and($freshIngredient->fattyAcidEntries)->not->toBeEmpty();
});

function writeCarrierOilChemistryFixture(string $directory, array $rows): string
{
    $path = $directory.'/carrier_oil_chemistry.json';

    file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    return $path;
}
