<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

const FOREIGN_KEY_INDEX_MANIFEST = [
    'catalogue' => [
        'migration' => 'add_catalog_foreign_key_indexes',
        'indexes' => [
            ['table' => 'ingredient_fatty_acids', 'column' => 'fatty_acid_id', 'name' => 'ingredient_fatty_acids_fatty_acid_id_index'],
            ['table' => 'current_material_prices', 'column' => 'ingredient_id', 'name' => 'current_material_prices_ingredient_id_index'],
            ['table' => 'current_material_prices', 'column' => 'packaging_item_id', 'name' => 'current_material_prices_packaging_item_id_index'],
            ['table' => 'ingredient_function_ingredient', 'column' => 'ingredient_function_id', 'name' => 'ingredient_function_ingredient_ingredient_function_id_index'],
            ['table' => 'recipe_versions', 'column' => 'ifra_product_category_id', 'name' => 'recipe_versions_ifra_product_category_id_index'],
            ['table' => 'recipe_versions', 'column' => 'regulatory_regime_id', 'name' => 'recipe_versions_regulatory_regime_id_index'],
        ],
    ],
    'procurement' => [
        'migration' => 'add_procurement_foreign_key_indexes',
        'indexes' => [
            ['table' => 'supplier_listings', 'column' => 'supplier_id', 'name' => 'supplier_listings_supplier_id_index'],
            ['table' => 'supplier_listings', 'column' => 'ingredient_id', 'name' => 'supplier_listings_ingredient_id_index'],
            ['table' => 'supplier_listings', 'column' => 'packaging_item_id', 'name' => 'supplier_listings_packaging_item_id_index'],
            ['table' => 'purchase_orders', 'column' => 'supplier_id', 'name' => 'purchase_orders_supplier_id_index'],
            ['table' => 'purchase_order_lines', 'column' => 'purchase_order_id', 'name' => 'purchase_order_lines_purchase_order_id_index'],
            ['table' => 'purchase_order_lines', 'column' => 'supplier_listing_id', 'name' => 'purchase_order_lines_supplier_listing_id_index'],
            ['table' => 'purchase_order_lines', 'column' => 'ingredient_id', 'name' => 'purchase_order_lines_ingredient_id_index'],
            ['table' => 'purchase_order_lines', 'column' => 'packaging_item_id', 'name' => 'purchase_order_lines_packaging_item_id_index'],
            ['table' => 'goods_receipts', 'column' => 'purchase_order_id', 'name' => 'goods_receipts_purchase_order_id_index'],
            ['table' => 'goods_receipt_lines', 'column' => 'goods_receipt_id', 'name' => 'goods_receipt_lines_goods_receipt_id_index'],
            ['table' => 'goods_receipt_lines', 'column' => 'purchase_order_line_id', 'name' => 'goods_receipt_lines_purchase_order_line_id_index'],
        ],
    ],
    'stock' => [
        'migration' => 'add_stock_foreign_key_indexes',
        'indexes' => [
            ['table' => 'stock_lots', 'column' => 'ingredient_id', 'name' => 'stock_lots_ingredient_id_index'],
            ['table' => 'stock_lots', 'column' => 'packaging_item_id', 'name' => 'stock_lots_packaging_item_id_index'],
            ['table' => 'stock_lots', 'column' => 'supplier_listing_id', 'name' => 'stock_lots_supplier_listing_id_index'],
            ['table' => 'stock_lots', 'column' => 'recipe_id', 'name' => 'stock_lots_recipe_id_index'],
            ['table' => 'stock_movements', 'column' => 'reversal_of_stock_movement_id', 'name' => 'stock_movements_reversal_of_stock_movement_id_index'],
        ],
    ],
    'production' => [
        'migration' => 'add_production_foreign_key_indexes',
        'indexes' => [
            ['table' => 'recipe_items', 'column' => 'recipe_version_id', 'name' => 'recipe_items_recipe_version_id_index'],
            ['table' => 'recipe_items', 'column' => 'recipe_phase_id', 'name' => 'recipe_items_recipe_phase_id_index'],
            ['table' => 'production_formula_lines', 'column' => 'recipe_item_id', 'name' => 'production_formula_lines_recipe_item_id_index'],
            ['table' => 'production_requirements', 'column' => 'recipe_item_id', 'name' => 'production_requirements_recipe_item_id_index'],
            ['table' => 'production_requirements', 'column' => 'recipe_version_packaging_item_id', 'name' => 'production_requirements_recipe_version_packaging_item_id_index'],
            ['table' => 'production_runs', 'column' => 'recipe_id', 'name' => 'production_runs_recipe_id_index'],
            ['table' => 'production_runs', 'column' => 'recipe_version_id', 'name' => 'production_runs_recipe_version_id_index'],
            ['table' => 'production_runs', 'column' => 'production_task_set_id', 'name' => 'production_runs_production_task_set_id_index'],
            ['table' => 'production_tasks', 'column' => 'production_task_set_id', 'name' => 'production_tasks_production_task_set_id_index'],
            ['table' => 'production_tasks', 'column' => 'production_task_set_item_id', 'name' => 'production_tasks_production_task_set_item_id_index'],
            ['table' => 'production_tasks', 'column' => 'employee_id', 'name' => 'production_tasks_employee_id_index'],
            ['table' => 'production_tasks', 'column' => 'department_id', 'name' => 'production_tasks_department_id_index'],
            ['table' => 'production_task_set_items', 'column' => 'production_task_type_id', 'name' => 'production_task_set_items_production_task_type_id_index'],
            ['table' => 'production_task_types', 'column' => 'department_id', 'name' => 'production_task_types_department_id_index'],
            ['table' => 'recipe_version_packaging_items', 'column' => 'recipe_version_id', 'name' => 'recipe_version_packaging_items_recipe_version_id_index'],
        ],
    ],
    'production-follow-up' => [
        'migration' => 'add_missing_production_foreign_key_indexes',
        'indexes' => [
            ['table' => 'production_runs', 'column' => 'output_ingredient_id', 'name' => 'production_runs_output_ingredient_id_index'],
            ['table' => 'production_run_number_issuances', 'column' => 'production_run_id', 'name' => 'production_run_number_issuances_production_run_id_index'],
            ['table' => 'production_run_number_issuances', 'column' => 'issued_by_user_id', 'name' => 'production_run_number_issuances_issued_by_user_id_index'],
            ['table' => 'recipes', 'column' => 'output_ingredient_id', 'name' => 'recipes_output_ingredient_id_index'],
        ],
    ],
    'workspace' => [
        'migration' => 'add_workspace_access_foreign_key_indexes',
        'indexes' => [
            ['table' => 'workspace_members', 'column' => 'user_id', 'name' => 'workspace_members_user_id_index'],
        ],
    ],
];

it('covers explicit foreign key indexes', function (string $group): void {
    assertNamedForeignKeyIndexes(FOREIGN_KEY_INDEX_MANIFEST[$group]['indexes'], true);
})->with(foreignKeyIndexGroups());

it('round trips explicit foreign key index migrations', function (string $group): void {
    if (filter_var(env('VERIFY_POSTGRESQL_INDEXES', false), FILTER_VALIDATE_BOOL)) {
        expect(Schema::getConnection()->getDriverName())->toBe('pgsql');
    }

    $manifest = FOREIGN_KEY_INDEX_MANIFEST[$group];
    $migrationPaths = glob(database_path("migrations/*_{$manifest['migration']}.php")) ?: [];

    expect($migrationPaths)->toHaveCount(1);

    $migration = require $migrationPaths[0];

    assertNamedForeignKeyIndexes($manifest['indexes'], true);

    $migration->down();

    assertNamedForeignKeyIndexes($manifest['indexes'], false);

    $migration->up();

    assertNamedForeignKeyIndexes($manifest['indexes'], true);
})->with(foreignKeyIndexGroups());

/**
 * @return array<string, array{string}>
 */
function foreignKeyIndexGroups(): array
{
    return collect(array_keys(FOREIGN_KEY_INDEX_MANIFEST))
        ->mapWithKeys(fn (string $group): array => [$group => [$group]])
        ->all();
}

/**
 * @param  list<array{table: string, column: string, name: string}>  $targets
 */
function assertNamedForeignKeyIndexes(array $targets, bool $shouldExist): void
{
    foreach (collect($targets)->groupBy('table') as $table => $tableTargets) {
        $indexes = collect(Schema::getIndexes($table));

        foreach ($tableTargets as $target) {
            $index = $indexes->first(
                fn (array $candidate): bool => ($candidate['name'] ?? null) === $target['name'],
            );

            if (! $shouldExist) {
                expect($index)->toBeNull("Expected {$target['name']} to be absent.");

                continue;
            }

            expect($index)->not->toBeNull("Expected {$target['name']} to exist.")
                ->and($index['columns'][0] ?? null)->toBe($target['column'])
                ->and(strlen($target['name']))->toBeLessThanOrEqual(63);
        }
    }
}
