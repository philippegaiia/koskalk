<?php

use App\Models\Ingredient;
use Database\Seeders\CarrierOilSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\IngredientCatalogSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

it('uses a stable catalog key without retaining import path identity', function () {
    expect(Schema::hasColumn((new Ingredient)->getTable(), 'catalog_key'))->toBeTrue()
        ->and(Schema::hasColumn((new Ingredient)->getTable(), 'source_file'))->toBeFalse()
        ->and(Schema::hasColumn((new Ingredient)->getTable(), 'source_key'))->toBeFalse()
        ->and(Schema::hasColumn((new Ingredient)->getTable(), 'source_code_prefix'))->toBeFalse();
});

it('uses the explicit taxonomy and capability schema without the legacy soap flag', function (): void {
    $table = (new Ingredient)->getTable();

    expect(Schema::hasColumns($table, [
        'category',
        'subcategory',
        'taxonomy_source',
        'taxonomy_reviewed_at',
        'taxonomy_reviewed_by_user_id',
        'cosing_reference',
        'is_soap_saponification_trusted',
        'requires_aromatic_compliance',
    ]))->toBeTrue()
        ->and(Schema::hasColumn($table, 'is_potentially_saponifiable'))->toBeFalse();
});

it('does not automatically seed either legacy ingredient catalog', function () {
    $databaseSeeder = new class extends DatabaseSeeder
    {
        /** @var array<int, class-string<Seeder>> */
        public array $calledSeeders = [];

        public function call($class, $silent = false, array $parameters = []): static
        {
            $this->calledSeeders = Arr::wrap($class);

            return $this;
        }
    };

    $databaseSeeder->run();

    expect($databaseSeeder->calledSeeders)
        ->not->toContain(IngredientCatalogSeeder::class)
        ->not->toContain(CarrierOilSeeder::class);
});
