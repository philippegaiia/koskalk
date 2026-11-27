<?php

use App\Models\Allergen;
use App\Models\Ingredient;
use App\Models\IngredientAllergenEntry;
use App\Models\RegulatoryRegime;
use App\Models\RegulatoryRegimeAllergen;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function legacyCatalogAllergen(string $inciName): Allergen
{
    return Allergen::factory()->create([
        'source_name' => 'EU allergen list',
        'source_file' => '/Users/someone/Downloads/allergens.csv',
        'inci_name' => $inciName,
    ]);
}

it('previews the alignment without writing it', function (): void {
    legacyCatalogAllergen('ALPHA-DAMASCONE');
    legacyCatalogAllergen('BETA-DAMASCONE');
    legacyCatalogAllergen('MENTHONE');

    $this->artisan('allergens:sync-eu-catalog')
        ->expectsOutputToContain('Dry run only: 81 entries: 80 to create, 1 to rename, 0 to update, 0 unchanged; 1 merged; 1 to delete, 0 kept for review.')
        ->assertSuccessful();

    expect(Allergen::query()->orderBy('inci_name')->pluck('inci_name')->all())
        ->toBe(['ALPHA-DAMASCONE', 'BETA-DAMASCONE', 'MENTHONE']);
});

it('aligns the catalog to the regulation while keeping ingredient compositions', function (): void {
    $eu = RegulatoryRegime::factory()->create(['code' => 'eu', 'market_code' => 'eu']);
    $alphaDamascone = legacyCatalogAllergen('ALPHA-DAMASCONE');
    $betaDamascone = legacyCatalogAllergen('BETA-DAMASCONE');
    $menthone = legacyCatalogAllergen('MENTHONE');
    $butylcyclohexylAcetate = legacyCatalogAllergen('4-TERT-BUTYLCYCLOHEXYL ACETATE');
    $roseOil = Ingredient::factory()->create();
    IngredientAllergenEntry::factory()->create(['ingredient_id' => $roseOil->id, 'allergen_id' => $alphaDamascone->id, 'concentration_percent' => 0.2]);
    IngredientAllergenEntry::factory()->create(['ingredient_id' => $roseOil->id, 'allergen_id' => $betaDamascone->id, 'concentration_percent' => 0.15]);
    IngredientAllergenEntry::factory()->create(['allergen_id' => $butylcyclohexylAcetate->id]);
    RegulatoryRegimeAllergen::factory()->create(['regulatory_regime_id' => $eu->id, 'allergen_id' => $butylcyclohexylAcetate->id, 'is_active' => true]);

    $this->artisan('allergens:sync-eu-catalog', ['--apply' => true])
        ->expectsOutputToContain('Allergen catalog aligned. 81 entries: 80 to create, 1 to rename, 0 to update, 0 unchanged; 1 merged; 1 to delete, 1 kept for review.')
        ->assertSuccessful();

    $roseKetones = Allergen::query()->where('inci_name', 'ROSE KETONES')->sole();
    $pinene = Allergen::query()->where('inci_name', 'PINENE')->sole();

    expect($roseKetones->id)->toBe($alphaDamascone->id)
        ->and($roseKetones->source_data['annex_ref'])->toBe('III/157')
        ->and($roseOil->allergenEntries()->sole())
        ->allergen_id->toBe($roseKetones->id)
        ->concentration_percent->toBe('0.35000')
        ->and(Allergen::query()->whereKey([$betaDamascone->id, $menthone->id])->exists())->toBeFalse()
        ->and($pinene->cas_number)->toBe('80-56-8 / 7785-70-8 / 127-91-3 / 18172-67-3')
        ->and(Allergen::query()->where('source_file', 'database/seeders/data/eu_fragrance_allergens.json')->count())->toBe(81)
        ->and(RegulatoryRegimeAllergen::query()->whereBelongsTo($eu)->where('is_active', true)->count())->toBe(81)
        ->and(RegulatoryRegimeAllergen::query()->whereBelongsTo($eu)->where('allergen_id', $butylcyclohexylAcetate->id)->sole()->is_active)->toBeFalse()
        ->and($butylcyclohexylAcetate->fresh())->not->toBeNull();
});

it('leaves an aligned catalog unchanged on a second run', function (): void {
    $this->artisan('allergens:sync-eu-catalog', ['--apply' => true])->assertSuccessful();

    $this->artisan('allergens:sync-eu-catalog')
        ->expectsOutputToContain('81 entries: 0 to create, 0 to rename, 0 to update, 81 unchanged; 0 merged; 0 to delete, 0 kept for review.')
        ->assertSuccessful();
});
