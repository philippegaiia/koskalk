<?php

use App\Enums\OrganicStatus;
use App\Enums\PackagingCategory;
use App\Models\Ingredient;
use App\Models\PackagingItem;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('defines contextual packaging categories and commercial organic states', function (): void {
    expect(array_column(PackagingCategory::cases(), 'value'))->toBe([
        'box',
        'jar',
        'bottle',
        'lid',
        'cap',
        'label',
        'tube',
        'pump',
        'shipping',
        'other',
    ])->and(array_column(OrganicStatus::cases(), 'value'))->toBe([
        'unknown',
        'conventional',
        'organic',
    ]);

    foreach (PackagingCategory::cases() as $category) {
        expect($category->getLabel())->toBe(__("packaging.categories.{$category->value}"));
    }
});

it('uses workspace packaging and clean generic ingredient columns', function (): void {
    expect(Schema::hasColumns('packaging_items', [
        'public_id',
        'workspace_id',
        'created_by_user_id',
        'name',
        'category',
        'notes',
        'is_active',
        'featured_image_path',
    ]))->toBeTrue()
        ->and(Schema::hasTable('user_packaging_items'))->toBeFalse()
        ->and(Schema::hasColumns('ingredients', ['notes']))->toBeTrue()
        ->and(Schema::hasColumns('ingredients', [
            'supplier_name',
            'supplier_reference',
            'is_organic',
        ]))->toBeFalse();
});

it('requires current prices to reference exactly one catalogue subject', function (): void {
    $workspace = Workspace::factory()->create();
    $ingredient = Ingredient::factory()->create();
    $packaging = PackagingItem::factory()->for($workspace)->create();

    expect(fn () => DB::table('current_material_prices')->insert([
        'workspace_id' => $workspace->id,
        'ingredient_id' => $ingredient->id,
        'packaging_item_id' => $packaging->id,
        'price_per_canonical_unit' => '0.004200000000',
        'currency' => 'EUR',
        'recorded_at' => now(),
    ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('current_material_prices')->insert([
            'workspace_id' => $workspace->id,
            'ingredient_id' => null,
            'packaging_item_id' => null,
            'price_per_canonical_unit' => '0.004200000000',
            'currency' => 'EUR',
            'recorded_at' => now(),
        ]))->toThrow(QueryException::class);
});
