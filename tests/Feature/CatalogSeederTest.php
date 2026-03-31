<?php

use App\IngredientCategory;
use App\Models\Allergen;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\ProductFamily;
use Database\Seeders\AllergenCatalogSeeder;
use Database\Seeders\IfraProductCategorySeeder;
use Database\Seeders\IngredientCatalogSeeder;
use Database\Seeders\IngredientFunctionSeeder;
use Database\Seeders\ProductFamilySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->catalogFixtureDirectory = sys_get_temp_dir().'/koskalk-catalog-'.bin2hex(random_bytes(8));

    mkdir($this->catalogFixtureDirectory, 0777, true);
});

afterEach(function () {
    collect(glob($this->catalogFixtureDirectory.'/*') ?: [])->each(fn (string $path) => unlink($path));

    if (is_dir($this->catalogFixtureDirectory)) {
        rmdir($this->catalogFixtureDirectory);
    }
});

it('seeds the initial catalog from both csv files', function () {
    [$allergenPath, $ingredientPath] = writeCatalogFixtures(
        $this->catalogFixtureDirectory,
        allergenRows: [
            ['Intro title', '', '', '', ''],
            ['Nom INCI (à étiqueter)', 'Numéro CAS (International)', 'Numéro CE (Européen)', '', ''],
            ['LINALOOL', '78-70-6', '201-134-4', 'Linalool', 'Linalol'],
            ['LIMONENE', '138-86-3', '205-341-0', 'Limonene', 'Limonène'],
        ],
        ingredientRows: [
            ['Code', 'Name', 'Category', 'Unit', 'Prix (€)', 'Min stock', 'Active', 'Fabriqué', 'INCI', 'INCI NaOH', 'INCI KOH', 'CAS', 'CAS EINECS', 'EINECS', 'Nom EN'],
            ['OB1', "Huile d'olive vierge", '', 'kg', '2.03', '0.000', 'Yes', 'No', 'Olea europaea fruit oil', 'Sodium olivate', 'Potassium olivate', '8001-25-00', '', '232-277-00', 'Olive oil virgin'],
            ['EO1', 'Huile essentielle de lavande', '', 'kg', '', '0.000', 'Yes', 'No', 'Lavandula angustifolia oil', '', '', '', '', '', 'Lavender essential oil'],
        ],
    );

    config()->set('catalog-imports.allergens.path', $allergenPath);
    config()->set('catalog-imports.ingredients.path', $ingredientPath);

    $this->seed([
        ProductFamilySeeder::class,
        AllergenCatalogSeeder::class,
        IngredientCatalogSeeder::class,
    ]);

    expect(ProductFamily::query()->where('slug', 'soap')->exists())->toBeTrue()
        ->and(Allergen::query()->count())->toBe(2)
        ->and(Ingredient::query()->count())->toBe(2);

    $oliveOil = Ingredient::query()->where('source_key', 'OB1')->firstOrFail();

    expect($oliveOil->display_name)->toBe('Olive oil virgin')
        ->and($oliveOil->display_name_en)->toBe('Olive oil virgin')
        ->and($oliveOil->inci_name)->toBe('Olea europaea fruit oil')
        ->and($oliveOil->soap_inci_naoh_name)->toBe('Sodium olivate')
        ->and($oliveOil->soap_inci_koh_name)->toBe('Potassium olivate');
});

it('updates existing ingredient rows without duplicating them on reseed', function () {
    [$allergenPath, $ingredientPath] = writeCatalogFixtures(
        $this->catalogFixtureDirectory,
        allergenRows: [
            ['Nom INCI (à étiqueter)', 'Numéro CAS (International)', 'Numéro CE (Européen)', '', ''],
            ['LINALOOL', '78-70-6', '201-134-4', 'Linalool', 'Linalol'],
        ],
        ingredientRows: [
            ['Code', 'Name', 'Category', 'Unit', 'Prix (€)', 'Min stock', 'Active', 'Fabriqué', 'INCI', 'INCI NaOH', 'INCI KOH', 'CAS', 'CAS EINECS', 'EINECS', 'Nom EN'],
            ['OB1', "Huile d'olive vierge", '', 'kg', '2.03', '0.000', 'Yes', 'No', 'Olea europaea fruit oil', 'Sodium olivate', 'Potassium olivate', '8001-25-00', '', '232-277-00', 'Olive oil virgin'],
        ],
    );

    config()->set('catalog-imports.allergens.path', $allergenPath);
    config()->set('catalog-imports.ingredients.path', $ingredientPath);

    $this->seed([
        AllergenCatalogSeeder::class,
        IngredientCatalogSeeder::class,
    ]);

    writeCsv($ingredientPath, [
        ['Code', 'Name', 'Category', 'Unit', 'Prix (€)', 'Min stock', 'Active', 'Fabriqué', 'INCI', 'INCI NaOH', 'INCI KOH', 'CAS', 'CAS EINECS', 'EINECS', 'Nom EN'],
        ['OB1', "Huile d'olive vierge", '', 'kg', '3.10', '0.000', 'Yes', 'No', 'Olea europaea fruit oil', 'Sodium olivate', 'Potassium olivate', '8001-25-00', '', '232-277-00', 'Olive oil virgin'],
    ]);

    $this->seed(IngredientCatalogSeeder::class);

    expect(Ingredient::query()->count())->toBe(1)
        ->and(Ingredient::query()->firstOrFail()->price_eur)->toBe('3.10');
});

it('classifies imported ingredients by code prefix and does not assume soap eligibility without soap names', function () {
    [, $ingredientPath] = writeCatalogFixtures(
        $this->catalogFixtureDirectory,
        allergenRows: [
            ['Nom INCI (à étiqueter)', 'Numéro CAS (International)', 'Numéro CE (Européen)', '', ''],
        ],
        ingredientRows: [
            ['Code', 'Name', 'Category', 'Unit', 'Prix (€)', 'Min stock', 'Active', 'Fabriqué', 'INCI', 'INCI NaOH', 'INCI KOH', 'CAS', 'CAS EINECS', 'EINECS', 'Nom EN'],
            ['OB1', "Huile d'olive vierge", '', 'kg', '', '0.000', 'Yes', 'No', 'Olea europaea fruit oil', 'Sodium olivate', 'Potassium olivate', '8001-25-00', '', '232-277-00', 'Olive oil virgin'],
            ['EO1', 'Huile essentielle de lavande', '', 'kg', '', '0.000', 'Yes', 'No', 'Lavandula angustifolia oil', '', '', '', '', '', 'Lavender essential oil'],
        ],
    );

    config()->set('catalog-imports.ingredients.path', $ingredientPath);

    $this->seed(IngredientCatalogSeeder::class);

    $oil = Ingredient::query()->where('source_key', 'OB1')->firstOrFail();
    $essentialOil = Ingredient::query()->where('source_key', 'EO1')->firstOrFail();

    expect($oil->category)->toBe(IngredientCategory::CarrierOil)
        ->and($oil->is_potentially_saponifiable)->toBeTrue()
        ->and($oil->requires_admin_review)->toBeTrue()
        ->and($oil->isAvailableForInitialSoapCalculation())->toBeTrue()
        ->and($essentialOil->category)->toBe(IngredientCategory::EssentialOil)
        ->and($essentialOil->is_potentially_saponifiable)->toBeFalse();
});

it('does not seed platform fragrance oils from the starter catalog', function () {
    [, $ingredientPath] = writeCatalogFixtures(
        $this->catalogFixtureDirectory,
        allergenRows: [
            ['Nom INCI (à étiqueter)', 'Numéro CAS (International)', 'Numéro CE (Européen)', '', ''],
        ],
        ingredientRows: [
            ['Code', 'Name', 'Category', 'Unit', 'Prix (€)', 'Min stock', 'Active', 'Fabriqué', 'INCI', 'INCI NaOH', 'INCI KOH', 'CAS', 'CAS EINECS', 'EINECS', 'Nom EN'],
            ['FR1', 'Parfum test', '', 'kg', '', '0.000', 'Yes', 'No', 'Parfum', '', '', '', '', '', 'Test fragrance oil'],
            ['EO1', 'Huile essentielle de lavande', '', 'kg', '', '0.000', 'Yes', 'No', 'Lavandula angustifolia oil', '', '', '', '', '', 'Lavender essential oil'],
        ],
    );

    config()->set('catalog-imports.ingredients.path', $ingredientPath);

    $this->seed(IngredientCatalogSeeder::class);

    expect(Ingredient::query()->pluck('source_key')->all())
        ->toBe(['EO1']);
});

it('classifies botanical and co2 extracts for later aromatic compliance enrichment', function () {
    [, $ingredientPath] = writeCatalogFixtures(
        $this->catalogFixtureDirectory,
        allergenRows: [
            ['Nom INCI (à étiqueter)', 'Numéro CAS (International)', 'Numéro CE (Européen)', '', ''],
        ],
        ingredientRows: [
            ['Code', 'Name', 'Category', 'Unit', 'Prix (€)', 'Min stock', 'Active', 'Fabriqué', 'INCI', 'INCI NaOH', 'INCI KOH', 'CAS', 'CAS EINECS', 'EINECS', 'Nom EN'],
            ['BE1', 'Extrait de calendula', '', 'kg', '', '0.000', 'Yes', 'No', 'Calendula officinalis extract', '', '', '', '', '', 'Calendula extract'],
            ['AR1', 'Romarin CO2 extract', '', 'kg', '', '0.000', 'Yes', 'No', 'Rosmarinus officinalis CO2 extract', '', '', '', '', '', 'Rosemary CO2 extract'],
        ],
    );

    config()->set('catalog-imports.ingredients.path', $ingredientPath);

    $this->seed(IngredientCatalogSeeder::class);

    expect(Ingredient::query()->where('source_key', 'BE1')->firstOrFail()->category)->toBe(IngredientCategory::BotanicalExtract)
        ->and(Ingredient::query()->where('source_key', 'AR1')->firstOrFail()->category)->toBe(IngredientCategory::Co2Extract);
});

it('preserves multi-value cas strings and keeps allergen imports out of ingredient tables', function () {
    [$allergenPath] = writeCatalogFixtures(
        $this->catalogFixtureDirectory,
        allergenRows: [
            ['EU LIST', '', '', '', ''],
            ['Nom INCI (à étiqueter)', 'Numéro CAS (International)', 'Numéro CE (Européen)', '', ''],
            ['ANETHOLE', '104-46-1 / 4180-23-8', '203-205-5', 'Anethole', 'Anéthole'],
        ],
        ingredientRows: [
            ['Code', 'Name', 'Category', 'Unit', 'Prix (€)', 'Min stock', 'Active', 'Fabriqué', 'INCI', 'INCI NaOH', 'INCI KOH', 'CAS', 'CAS EINECS', 'EINECS', 'Nom EN'],
        ],
    );

    config()->set('catalog-imports.allergens.path', $allergenPath);

    $this->seed(AllergenCatalogSeeder::class);

    $allergen = Allergen::query()->firstOrFail();

    expect(Allergen::query()->count())->toBe(1)
        ->and($allergen->cas_number)->toBe('104-46-1 / 4180-23-8')
        ->and(Ingredient::query()->count())->toBe(0);
});

it('seeds official ingredient functions and ifra product categories', function () {
    $this->seed([
        IngredientFunctionSeeder::class,
        IfraProductCategorySeeder::class,
    ]);

    $emollient = IngredientFunction::query()->where('key', 'emollient')->firstOrFail();
    $category9 = IfraProductCategory::query()->where('code', '9')->firstOrFail();

    expect(IngredientFunction::query()->count())->toBeGreaterThan(20)
        ->and($emollient->name)->toBe('Emollient')
        ->and($emollient->description)->toContain('Softens')
        ->and(IfraProductCategory::query()->count())->toBe(18)
        ->and($category9->short_name)->toBe('Soap / shower gel / rinse-off')
        ->and($category9->description)->toContain('primarily rinse-off');
});

function writeCatalogFixtures(string $directory, array $allergenRows, array $ingredientRows): array
{
    $allergenPath = $directory.'/allergens.csv';
    $ingredientPath = $directory.'/ingredients.csv';

    writeCsv($allergenPath, $allergenRows);
    writeCsv($ingredientPath, $ingredientRows);

    return [$allergenPath, $ingredientPath];
}

function writeCsv(string $path, array $rows): void
{
    $handle = fopen($path, 'w');

    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    fclose($handle);
}
