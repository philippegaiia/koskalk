<?php

use App\Enums\IngredientFunctionSource;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    cache()->flush();
    config()->set('ingredient-enrichment.sources.cosing_checker', [
        'base_url' => 'https://cosingchecker.test/api/v1',
        'inventory_version' => 'inventory-2026-03-21',
        'ttl_days' => 30,
    ]);

    foreach (['Fragrance', 'Perfuming', 'Humectant', 'Skin conditioning', 'Skin conditioning - emollient'] as $index => $name) {
        IngredientFunction::factory()->create([
            'key' => str($name)->replace(' - ', '_')->snake()->toString(),
            'name' => $name,
            'sort_order' => $index + 1,
            'is_active' => true,
        ]);
    }
});

/**
 * @param  array<string, string>  $links  function key => link source
 */
function refreshCosIngIngredient(string $inciName, array $links): Ingredient
{
    $ingredient = Ingredient::factory()->create(['catalog_key' => 'cocoa_butter', 'inci_name' => $inciName]);

    foreach ($links as $key => $source) {
        $ingredient->functions()->attach(IngredientFunction::query()->where('key', $key)->sole()->id, [
            'source' => $source,
            'source_reference' => 'https://cosingchecker.com/ingredients/old/',
        ]);
    }

    return $ingredient;
}

function fakeRefreshCosIngRecord(string $inciName, string $functions): void
{
    Http::preventStrayRequests();
    Http::fake([
        'cosingchecker.test/*' => Http::response([
            'count' => 1,
            'results' => [[
                'slug' => '60291-theobroma-cacao-seed-butter',
                'ref_number' => '60291',
                'inci_name' => $inciName,
                'cas_number' => '84649-99-0',
                'ec_number' => '283-480-6',
                'description' => 'Cocoa seed butter.',
                'restriction' => null,
                'function' => $functions,
                'update_date' => '15/10/2010',
            ]],
        ]),
    ]);
}

/**
 * @return array<string, string>
 */
function refreshCosIngLinks(Ingredient $ingredient): array
{
    return $ingredient->fresh()->functions
        ->mapWithKeys(fn (IngredientFunction $function): array => [$function->key => $function->pivot->source])
        ->sortKeys()
        ->all();
}

it('previews the CosIng function changes without writing them', function (): void {
    $ingredient = refreshCosIngIngredient('THEOBROMA CACAO SEED BUTTER', [
        'perfuming' => IngredientFunctionSource::CosIng->value,
        'skin_conditioning' => IngredientFunctionSource::CosIng->value,
    ]);
    fakeRefreshCosIngRecord('THEOBROMA CACAO SEED BUTTER', 'FRAGRANCE, SKIN CONDITIONING, SKIN CONDITIONING - EMOLLIENT');

    $this->artisan('ingredients:refresh-cosing-functions')
        ->expectsTable(
            ['Catalog key', 'Ingredient', 'Status', 'Added', 'Removed', 'Reason'],
            [['cocoa_butter', $ingredient->display_name, 'change', 'fragrance, skin_conditioning_emollient', 'perfuming', '']],
        )
        ->expectsOutputToContain('Dry run only: 1 to change, 0 unchanged, 0 skipped.')
        ->assertSuccessful();

    expect(refreshCosIngLinks($ingredient))->toBe([
        'perfuming' => 'cosing',
        'skin_conditioning' => 'cosing',
    ]);
});

it('replaces only CosIng links and keeps functions chosen by hand', function (): void {
    $ingredient = refreshCosIngIngredient('THEOBROMA CACAO SEED BUTTER', [
        'perfuming' => IngredientFunctionSource::CosIng->value,
        'fragrance' => IngredientFunctionSource::Manual->value,
        'humectant' => IngredientFunctionSource::Manual->value,
    ]);
    fakeRefreshCosIngRecord('THEOBROMA CACAO SEED BUTTER', 'FRAGRANCE, SKIN CONDITIONING - EMOLLIENT');

    $this->artisan('ingredients:refresh-cosing-functions', ['--apply' => true])
        ->expectsOutputToContain('CosIng functions refreshed: 1 ingredients updated.')
        ->assertSuccessful();

    expect(refreshCosIngLinks($ingredient))->toBe([
        'fragrance' => 'manual',
        'humectant' => 'manual',
        'skin_conditioning_emollient' => 'cosing',
    ])->and($ingredient->fresh()->functions->firstWhere('key', 'skin_conditioning_emollient')->pivot)
        ->source_reference->toBe('https://cosingchecker.com/ingredients/60291-theobroma-cacao-seed-butter/')
        ->source_tier->toBe('structured_mirror');
});

it('skips an ingredient whose CosIng match is not its stored INCI', function (): void {
    $ingredient = refreshCosIngIngredient('THEOBROMA CACAO SEED BUTTER', [
        'perfuming' => IngredientFunctionSource::CosIng->value,
    ]);
    fakeRefreshCosIngRecord('HYDROLYZED THEOBROMA CACAO SEED BUTTER', 'SKIN CONDITIONING');

    $this->artisan('ingredients:refresh-cosing-functions', ['--apply' => true])
        ->expectsOutputToContain('0 ingredients updated. 0 to change, 0 unchanged, 1 skipped.')
        ->assertSuccessful();

    expect(refreshCosIngLinks($ingredient))->toBe(['perfuming' => 'cosing']);
});
