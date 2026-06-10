# Production Snapshots V1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add durable production snapshots that freeze a saved formula's batch basis, ingredient quantities, packaging use, prices, totals, notes, and lot numbers at the moment production is recorded.

**Architecture:** Add production snapshot tables and focused Eloquent models, then route production recording through a small preview/recording service that reads the current saved recipe, live costing rows, and packaging plan. The saved formula page becomes the entry point, while production snapshot show/print pages read only frozen snapshot rows. Live price propagation is kept separate so catalog price edits update mutable live costing rows without touching production snapshots.

**Tech Stack:** Laravel 13, PHP 8.5, Blade, Tailwind v4 utility classes, Pest 4, Laravel Pint, graphify.

---

## Guardrails

- Before touching Laravel, Filament, Livewire, or Pest code, use Laravel Boost `search-docs` for the specific framework topic being edited.
- Use the `pest-testing` skill for every test-writing or test-fixing task.
- Use the `tailwindcss-development` skill for the Blade form and table styling tasks.
- Run `vendor/bin/pint --dirty --format agent` after PHP edits.
- Run `graphify update .` after code edits.
- Stage only files created or modified by the current task.

## File Map

- Create `database/migrations/2026_06_10_120000_create_production_batches_table.php` for production snapshot headers.
- Create `database/migrations/2026_06_10_120001_create_production_batch_ingredients_table.php` for frozen ingredient rows and editable lot numbers.
- Create `database/migrations/2026_06_10_120002_create_production_batch_packaging_items_table.php` for frozen packaging rows.
- Create `app/Models/ProductionBatch.php`, `app/Models/ProductionBatchIngredient.php`, and `app/Models/ProductionBatchPackagingItem.php`.
- Create `database/factories/ProductionBatchFactory.php`, `database/factories/ProductionBatchIngredientFactory.php`, and `database/factories/ProductionBatchPackagingItemFactory.php`.
- Modify `app/Models/User.php`, `app/Models/Recipe.php`, and `app/Models/RecipeVersion.php` to expose production batch relationships.
- Create `app/Policies/ProductionBatchPolicy.php` for owner-only snapshot access.
- Create `app/Services/RecipeVersionCostPreviewBuilder.php` to normalize current ingredient and packaging costs for saved formula views and snapshot recording.
- Modify `app/Services/RecipeVersionViewDataBuilder.php` to use the cost preview builder and return production-ready numeric costing rows.
- Create `app/Services/ProductionSnapshotService.php` to preview and create production batches inside a transaction.
- Create `app/Http/Requests/StoreProductionBatchRequest.php` and `app/Http/Requests/UpdateProductionBatchAnnotationsRequest.php`.
- Create `app/Http/Controllers/ProductionBatchController.php`.
- Modify `routes/web.php` to add production batch store/show/update/print routes.
- Modify `resources/views/recipes/version.blade.php` to replace the GET-only prepare batch panel with Record production and production history.
- Create `resources/views/production-batches/show.blade.php`.
- Create `resources/views/production-batches/print.blade.php`.
- Create `app/Services/LiveCostingPricePropagationService.php`.
- Modify `app/Services/UserIngredientPriceMemory.php`, `app/Services/UserPackagingItemAuthoringService.php`, and `app/Services/RecipeVersionCostingSynchronizer.php` to propagate current price edits into linked live costing rows.
- Create `tests/Feature/ProductionSnapshotsTest.php`.
- Modify `tests/Feature/RecipeVersionPackagingPlanTest.php` and `tests/Feature/RecipeVersionCostingTest.php` for UI and price propagation coverage.

---

### Task 1: Production Snapshot Schema And Models

**Files:**
- Create: `database/migrations/2026_06_10_120000_create_production_batches_table.php`
- Create: `database/migrations/2026_06_10_120001_create_production_batch_ingredients_table.php`
- Create: `database/migrations/2026_06_10_120002_create_production_batch_packaging_items_table.php`
- Create: `app/Models/ProductionBatch.php`
- Create: `app/Models/ProductionBatchIngredient.php`
- Create: `app/Models/ProductionBatchPackagingItem.php`
- Create: `database/factories/ProductionBatchFactory.php`
- Create: `database/factories/ProductionBatchIngredientFactory.php`
- Create: `database/factories/ProductionBatchPackagingItemFactory.php`
- Modify: `app/Models/User.php`
- Modify: `app/Models/Recipe.php`
- Modify: `app/Models/RecipeVersion.php`
- Create: `app/Policies/ProductionBatchPolicy.php`
- Test: `tests/Feature/ProductionSnapshotsTest.php`

- [ ] **Step 1: Search docs and scaffold files**

Run:

```bash
php artisan make:model ProductionBatch --factory --no-interaction
php artisan make:model ProductionBatchIngredient --factory --no-interaction
php artisan make:model ProductionBatchPackagingItem --factory --no-interaction
php artisan make:policy ProductionBatchPolicy --model=ProductionBatch --no-interaction
php artisan make:migration create_production_batches_table --create=production_batches --no-interaction
php artisan make:migration create_production_batch_ingredients_table --create=production_batch_ingredients --no-interaction
php artisan make:migration create_production_batch_packaging_items_table --create=production_batch_packaging_items --no-interaction
php artisan make:test --pest ProductionSnapshotsTest --no-interaction
```

Expected: each command reports a created file. Rename the generated migration files to the three exact migration paths listed in this task so the migration order is deterministic.

- [ ] **Step 2: Write the failing schema/model test**

Add this test to `tests/Feature/ProductionSnapshotsTest.php`:

```php
<?php

use App\Models\ProductionBatch;
use App\Models\ProductionBatchIngredient;
use App\Models\ProductionBatchPackagingItem;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores production snapshot headers with frozen ingredient and packaging rows', function (): void {
    $user = User::factory()->create();
    $recipe = Recipe::factory()->create(['owner_id' => $user->id]);
    $version = RecipeVersion::factory()->create([
        'recipe_id' => $recipe->id,
        'owner_id' => $user->id,
        'is_draft' => false,
        'version_number' => 3,
        'batch_size' => 1000,
        'batch_unit' => 'g',
    ]);

    $batch = ProductionBatch::factory()
        ->for($user)
        ->for($recipe)
        ->for($version, 'recipeVersion')
        ->create([
            'recipe_name' => 'Castile Soap',
            'recipe_version_number' => 3,
            'product_family_slug' => 'soap',
            'production_batch_number' => 'B-2026-001',
            'manufacture_date' => '2026-06-10',
            'batch_basis_label' => 'Oil quantity',
            'batch_basis_value' => 1000,
            'batch_basis_unit' => 'g',
            'units_produced' => 12,
            'currency' => 'EUR',
            'ingredient_total' => 8.5,
            'packaging_total' => 3,
            'total_cost' => 11.5,
            'cost_per_unit' => 0.9583,
            'production_notes' => 'Trace accelerated slightly.',
        ]);

    ProductionBatchIngredient::factory()
        ->for($batch)
        ->create([
            'phase_key' => 'saponified_oils',
            'phase_name' => 'Saponified oils',
            'position' => 1,
            'ingredient_name' => 'Olive Oil',
            'percentage' => 100,
            'quantity' => 1000,
            'unit' => 'g',
            'price_per_kg' => 8.5,
            'line_cost' => 8.5,
            'ingredient_lot_number' => 'OO-LOT-1',
        ]);

    ProductionBatchPackagingItem::factory()
        ->for($batch)
        ->create([
            'position' => 1,
            'name' => 'Soap box',
            'components_per_unit' => 1,
            'unit_cost' => 0.25,
            'cost_per_finished_unit' => 0.25,
            'line_cost' => 3,
        ]);

    expect($batch->ingredients)->toHaveCount(1)
        ->and($batch->packagingItems)->toHaveCount(1)
        ->and($batch->ingredients->first()->ingredient_lot_number)->toBe('OO-LOT-1')
        ->and($batch->packagingItems->first()->line_cost)->toBe('3.0000')
        ->and($recipe->productionBatches()->first()->is($batch))->toBeTrue()
        ->and($version->productionBatches()->first()->is($batch))->toBeTrue()
        ->and($user->productionBatches()->first()->is($batch))->toBeTrue();
});
```

- [ ] **Step 3: Run the failing test**

Run:

```bash
php artisan test --compact --filter="stores production snapshot headers"
```

Expected: FAIL because the production tables, models, factories, and relationships do not exist yet.

- [ ] **Step 4: Implement migrations**

Replace `database/migrations/2026_06_10_120000_create_production_batches_table.php` with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipe_name');
            $table->unsignedInteger('recipe_version_number');
            $table->string('product_family_slug', 64);
            $table->string('production_batch_number', 120)->nullable();
            $table->date('manufacture_date');
            $table->string('batch_basis_label', 64);
            $table->decimal('batch_basis_value', total: 12, places: 3);
            $table->string('batch_basis_unit', 16);
            $table->unsignedInteger('units_produced');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('ingredient_total', total: 18, places: 4)->default(0);
            $table->decimal('packaging_total', total: 18, places: 4)->default(0);
            $table->decimal('total_cost', total: 18, places: 4)->default(0);
            $table->decimal('cost_per_unit', total: 18, places: 4)->default(0);
            $table->text('production_notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'recipe_id', 'manufacture_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batches');
    }
};
```

Replace `database/migrations/2026_06_10_120001_create_production_batch_ingredients_table.php` with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_batch_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('raw_material_lot_id')->nullable()->index();
            $table->string('phase_key', 64);
            $table->string('phase_name');
            $table->unsignedInteger('position');
            $table->string('ingredient_name');
            $table->decimal('percentage', total: 9, places: 4);
            $table->decimal('quantity', total: 12, places: 4);
            $table->string('unit', 16);
            $table->decimal('price_per_kg', total: 18, places: 4)->nullable();
            $table->decimal('line_cost', total: 18, places: 4)->default(0);
            $table->string('ingredient_lot_number', 120)->nullable();
            $table->timestamps();

            $table->index(['production_batch_id', 'phase_key', 'position'], 'production_batch_ingredients_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batch_ingredients');
    }
};
```

Replace `database/migrations/2026_06_10_120002_create_production_batch_packaging_items_table.php` with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_batch_packaging_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_packaging_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position');
            $table->string('name');
            $table->decimal('components_per_unit', total: 10, places: 3);
            $table->decimal('unit_cost', total: 18, places: 4);
            $table->decimal('cost_per_finished_unit', total: 18, places: 4)->default(0);
            $table->decimal('line_cost', total: 18, places: 4)->default(0);
            $table->timestamps();

            $table->index(['production_batch_id', 'position'], 'production_batch_packaging_items_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_batch_packaging_items');
    }
};
```

- [ ] **Step 5: Implement models and relationships**

Replace `app/Models/ProductionBatch.php` with:

```php
<?php

namespace App\Models;

use Database\Factories\ProductionBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'recipe_id',
    'recipe_version_id',
    'recipe_name',
    'recipe_version_number',
    'product_family_slug',
    'production_batch_number',
    'manufacture_date',
    'batch_basis_label',
    'batch_basis_value',
    'batch_basis_unit',
    'units_produced',
    'currency',
    'ingredient_total',
    'packaging_total',
    'total_cost',
    'cost_per_unit',
    'production_notes',
])]
class ProductionBatch extends Model
{
    /** @use HasFactory<ProductionBatchFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function recipeVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class);
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(ProductionBatchIngredient::class)->orderBy('phase_key')->orderBy('position');
    }

    public function packagingItems(): HasMany
    {
        return $this->hasMany(ProductionBatchPackagingItem::class)->orderBy('position');
    }

    protected function casts(): array
    {
        return [
            'manufacture_date' => 'date',
            'batch_basis_value' => 'decimal:3',
            'units_produced' => 'integer',
            'ingredient_total' => 'decimal:4',
            'packaging_total' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'cost_per_unit' => 'decimal:4',
        ];
    }
}
```

Replace `app/Models/ProductionBatchIngredient.php` with:

```php
<?php

namespace App\Models;

use Database\Factories\ProductionBatchIngredientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'production_batch_id',
    'ingredient_id',
    'raw_material_lot_id',
    'phase_key',
    'phase_name',
    'position',
    'ingredient_name',
    'percentage',
    'quantity',
    'unit',
    'price_per_kg',
    'line_cost',
    'ingredient_lot_number',
])]
class ProductionBatchIngredient extends Model
{
    /** @use HasFactory<ProductionBatchIngredientFactory> */
    use HasFactory;

    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'percentage' => 'decimal:4',
            'quantity' => 'decimal:4',
            'price_per_kg' => 'decimal:4',
            'line_cost' => 'decimal:4',
        ];
    }
}
```

Replace `app/Models/ProductionBatchPackagingItem.php` with:

```php
<?php

namespace App\Models;

use Database\Factories\ProductionBatchPackagingItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'production_batch_id',
    'user_packaging_item_id',
    'position',
    'name',
    'components_per_unit',
    'unit_cost',
    'cost_per_finished_unit',
    'line_cost',
])]
class ProductionBatchPackagingItem extends Model
{
    /** @use HasFactory<ProductionBatchPackagingItemFactory> */
    use HasFactory;

    public function productionBatch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class);
    }

    public function packagingItem(): BelongsTo
    {
        return $this->belongsTo(UserPackagingItem::class, 'user_packaging_item_id');
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'components_per_unit' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'cost_per_finished_unit' => 'decimal:4',
            'line_cost' => 'decimal:4',
        ];
    }
}
```

Add these relationship methods:

```php
// app/Models/User.php
public function productionBatches(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(\App\Models\ProductionBatch::class)->latest('manufacture_date')->latest('id');
}

// app/Models/Recipe.php
public function productionBatches(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(ProductionBatch::class)->latest('manufacture_date')->latest('id');
}

// app/Models/RecipeVersion.php
public function productionBatches(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(ProductionBatch::class)->latest('manufacture_date')->latest('id');
}
```

- [ ] **Step 6: Implement factories**

Replace `database/factories/ProductionBatchFactory.php` with:

```php
<?php

namespace Database\Factories;

use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductionBatch> */
class ProductionBatchFactory extends Factory
{
    protected $model = ProductionBatch::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'recipe_id' => Recipe::factory(),
            'recipe_version_id' => RecipeVersion::factory(),
            'recipe_name' => fake()->words(2, true),
            'recipe_version_number' => 1,
            'product_family_slug' => 'soap',
            'production_batch_number' => fake()->bothify('B-####'),
            'manufacture_date' => fake()->date(),
            'batch_basis_label' => 'Oil quantity',
            'batch_basis_value' => 1000,
            'batch_basis_unit' => 'g',
            'units_produced' => 12,
            'currency' => 'EUR',
            'ingredient_total' => 8.5,
            'packaging_total' => 3,
            'total_cost' => 11.5,
            'cost_per_unit' => 0.9583,
            'production_notes' => null,
        ];
    }
}
```

Replace `database/factories/ProductionBatchIngredientFactory.php` with:

```php
<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchIngredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductionBatchIngredient> */
class ProductionBatchIngredientFactory extends Factory
{
    protected $model = ProductionBatchIngredient::class;

    public function definition(): array
    {
        return [
            'production_batch_id' => ProductionBatch::factory(),
            'ingredient_id' => Ingredient::factory(),
            'raw_material_lot_id' => null,
            'phase_key' => 'saponified_oils',
            'phase_name' => 'Saponified oils',
            'position' => 1,
            'ingredient_name' => 'Olive Oil',
            'percentage' => 100,
            'quantity' => 1000,
            'unit' => 'g',
            'price_per_kg' => 8.5,
            'line_cost' => 8.5,
            'ingredient_lot_number' => null,
        ];
    }
}
```

Replace `database/factories/ProductionBatchPackagingItemFactory.php` with:

```php
<?php

namespace Database\Factories;

use App\Models\ProductionBatch;
use App\Models\ProductionBatchPackagingItem;
use App\Models\UserPackagingItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductionBatchPackagingItem> */
class ProductionBatchPackagingItemFactory extends Factory
{
    protected $model = ProductionBatchPackagingItem::class;

    public function definition(): array
    {
        return [
            'production_batch_id' => ProductionBatch::factory(),
            'user_packaging_item_id' => UserPackagingItem::factory(),
            'position' => 1,
            'name' => 'Soap box',
            'components_per_unit' => 1,
            'unit_cost' => 0.25,
            'cost_per_finished_unit' => 0.25,
            'line_cost' => 3,
        ];
    }
}
```

- [ ] **Step 7: Implement production batch policy**

Replace `app/Policies/ProductionBatchPolicy.php` with:

```php
<?php

namespace App\Policies;

use App\Models\ProductionBatch;
use App\Models\User;

class ProductionBatchPolicy
{
    public function view(User $user, ProductionBatch $productionBatch): bool
    {
        return $productionBatch->user_id === $user->id;
    }

    public function update(User $user, ProductionBatch $productionBatch): bool
    {
        return $this->view($user, $productionBatch);
    }

    public function delete(User $user, ProductionBatch $productionBatch): bool
    {
        return false;
    }
}
```

- [ ] **Step 8: Run schema/model test**

Run:

```bash
php artisan test --compact --filter="stores production snapshot headers"
```

Expected: PASS.

- [ ] **Step 9: Format and commit**

Run:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter="stores production snapshot headers"
git add database/migrations/2026_06_10_120000_create_production_batches_table.php database/migrations/2026_06_10_120001_create_production_batch_ingredients_table.php database/migrations/2026_06_10_120002_create_production_batch_packaging_items_table.php app/Models/ProductionBatch.php app/Models/ProductionBatchIngredient.php app/Models/ProductionBatchPackagingItem.php database/factories/ProductionBatchFactory.php database/factories/ProductionBatchIngredientFactory.php database/factories/ProductionBatchPackagingItemFactory.php app/Models/User.php app/Models/Recipe.php app/Models/RecipeVersion.php app/Policies/ProductionBatchPolicy.php tests/Feature/ProductionSnapshotsTest.php
git commit -m "feat: add production snapshot models"
```

Expected: Pint reports clean/fixed files, test passes, and commit succeeds.

---

### Task 2: Cost Preview Builder For Current Saved Formula Data

**Files:**
- Create: `app/Services/RecipeVersionCostPreviewBuilder.php`
- Modify: `app/Services/RecipeVersionViewDataBuilder.php`
- Modify: `tests/Feature/ProductionSnapshotsTest.php`

- [ ] **Step 1: Write the failing preview test**

Append this test to `tests/Feature/ProductionSnapshotsTest.php`:

```php
use App\Models\Ingredient;
use App\Models\RecipeVersionCosting;
use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\UserPackagingItem;
use App\Services\RecipeVersionCostPreviewBuilder;

it('builds numeric production cost preview rows from live costing data', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => 0.25,
        'currency' => 'EUR',
    ]);

    $version->packagingItems()->create([
        'user_packaging_item_id' => $packagingItem->id,
        'name' => 'Soap box',
        'components_per_unit' => 1,
        'position' => 1,
    ]);

    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 10,
        'currency' => 'EUR',
    ]);

    RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'saponified_oils',
        'position' => 1,
        'price_per_kg' => 8.5,
    ]);

    RecipeVersionCostingPackagingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'user_packaging_item_id' => $packagingItem->id,
        'name' => 'Soap box',
        'unit_cost' => 0.25,
        'quantity' => 1,
    ]);

    $preview = app(RecipeVersionCostPreviewBuilder::class)->build(
        recipe: $recipe,
        version: $version,
        user: $user,
        batchBasisValue: 1500,
        unitsProduced: 12,
    );

    expect($preview['currency'])->toBe('EUR')
        ->and($preview['ingredient_total'])->toBe(12.75)
        ->and($preview['packaging_total'])->toBe(3.0)
        ->and($preview['total_cost'])->toBe(15.75)
        ->and($preview['cost_per_unit'])->toBe(1.3125)
        ->and($preview['has_unpriced_rows'])->toBeFalse()
        ->and($preview['ingredient_rows'][0]['ingredient_id'])->toBe($ingredient->id)
        ->and($preview['ingredient_rows'][0]['phase_key'])->toBe('saponified_oils')
        ->and($preview['ingredient_rows'][0]['phase_name'])->toBe('Saponified oils')
        ->and($preview['ingredient_rows'][0]['percentage'])->toBe(100.0)
        ->and($preview['ingredient_rows'][0]['quantity'])->toBe(1500.0)
        ->and($preview['ingredient_rows'][0]['unit'])->toBe('g')
        ->and($preview['ingredient_rows'][0]['line_cost'])->toBe(12.75)
        ->and($preview['packaging_rows'][0]['user_packaging_item_id'])->toBe($packagingItem->id)
        ->and($preview['packaging_rows'][0]['components_per_unit'])->toBe(1.0)
        ->and($preview['packaging_rows'][0]['line_cost'])->toBe(3.0);
});
```

- [ ] **Step 2: Add test helper functions**

Append these helper functions to the bottom of `tests/Feature/ProductionSnapshotsTest.php`:

```php
use App\IngredientCategory;
use App\Models\IngredientSapProfile;
use App\Models\ProductFamily;
use App\Models\ProductType;
use App\Services\RecipeWorkbenchService;

/**
 * @return array{0: User, 1: Recipe, 2: RecipeVersion, 3: Ingredient}
 */
function productionSnapshotSoapRecipe(): array
{
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
        'calculation_basis' => 'oil_weight',
    ]);
    $ingredient = productionSnapshotIngredient();

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft(
        $user,
        $soapFamily,
        productionSnapshotDraftPayload($ingredient, 'Snapshot Soap'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $service->saveAsNewVersion(
        $user,
        $soapFamily,
        productionSnapshotDraftPayload($ingredient, 'Snapshot Soap'),
        $recipe,
    );

    $version = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    return [$user, $recipe, $version, $ingredient];
}

function productionSnapshotIngredient(): Ingredient
{
    $ingredient = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'inci_name' => 'OLEA EUROPAEA FRUIT OIL',
        'soap_inci_naoh_name' => 'SODIUM OLIVATE',
        'soap_inci_koh_name' => 'POTASSIUM OLIVATE',
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);

    IngredientSapProfile::factory()->create([
        'ingredient_id' => $ingredient->id,
        'koh_sap_value' => 0.188,
    ]);

    return $ingredient;
}

/**
 * @return array<string, mixed>
 */
function productionSnapshotDraftPayload(Ingredient $ingredient, string $name): array
{
    return [
        'name' => $name,
        'oil_unit' => 'g',
        'oil_weight' => 1000,
        'manufacturing_mode' => 'saponify_in_formula',
        'exposure_mode' => 'rinse_off',
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'lye_type' => 'naoh',
        'koh_purity_percentage' => 90,
        'dual_lye_koh_percentage' => 40,
        'water_mode' => 'percent_of_oils',
        'water_value' => 38,
        'superfat' => 5,
        'ifra_product_category_id' => null,
        'phase_items' => [
            'saponified_oils' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'percentage' => 100,
                    'weight' => 1000,
                    'note' => null,
                ],
            ],
            'additives' => [],
            'fragrance' => [],
        ],
    ];
}
```

- [ ] **Step 3: Run the failing preview test**

Run:

```bash
php artisan test --compact --filter="builds numeric production cost preview rows"
```

Expected: FAIL because `RecipeVersionCostPreviewBuilder` does not exist.

- [ ] **Step 4: Implement the preview builder**

Create `app/Services/RecipeVersionCostPreviewBuilder.php`:

```php
<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\RecipeItem;
use App\Models\RecipePhase;
use App\Models\RecipeVersion;
use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\RecipeVersionPackagingItem;
use App\Models\User;

class RecipeVersionCostPreviewBuilder
{
    public function __construct(
        private readonly RecipeVersionCostingSynchronizer $costingSynchronizer,
    ) {}

    /**
     * @return array{
     *     currency: string,
     *     ingredient_rows: array<int, array<string, mixed>>,
     *     packaging_rows: array<int, array<string, mixed>>,
     *     ingredient_total: float,
     *     packaging_total: float,
     *     total_cost: float,
     *     cost_per_unit: float,
     *     has_unpriced_rows: bool
     * }
     */
    public function build(Recipe $recipe, RecipeVersion $version, User $user, float $batchBasisValue, ?int $unitsProduced): array
    {
        $costing = $this->costingSynchronizer->ensureCosting($version, $user);
        $currency = $costing->currency ?: $user->defaultCurrency();
        $unit = $version->batch_unit ?: 'g';

        $version = RecipeVersion::withoutGlobalScopes()
            ->with([
                'phases' => fn ($query) => $query->withoutGlobalScopes()->orderBy('sort_order'),
                'phases.items' => fn ($query) => $query->withoutGlobalScopes()->with('ingredient')->orderBy('position'),
                'packagingItems' => fn ($query) => $query->withoutGlobalScopes()->with('packagingItem')->orderBy('position'),
            ])
            ->findOrFail($version->id);

        $pricesByKey = $costing->items
            ->keyBy(fn (RecipeVersionCostingItem $item): string => $this->costingKey(
                (int) $item->ingredient_id,
                $item->phase_key,
                (int) $item->position,
            ));

        $ingredientRows = $version->phases
            ->flatMap(fn (RecipePhase $phase) => $phase->items
                ->map(function (RecipeItem $item) use ($batchBasisValue, $phase, $pricesByKey, $unit): array {
                    $priceRow = $pricesByKey->get($this->costingKey((int) $item->ingredient_id, $phase->slug, (int) $item->position));
                    $quantity = round($batchBasisValue * ((float) $item->percentage / 100), 4);
                    $pricePerKg = $priceRow?->price_per_kg === null ? null : (float) $priceRow->price_per_kg;
                    $lineCost = $pricePerKg === null ? 0.0 : round(($quantity / 1000) * $pricePerKg, 4);

                    return [
                        'lot_key' => $this->lotKey((int) $item->ingredient_id, $phase->slug, (int) $item->position),
                        'ingredient_id' => (int) $item->ingredient_id,
                        'phase_key' => $phase->slug,
                        'phase_name' => $phase->name,
                        'position' => (int) $item->position,
                        'ingredient_name' => $item->ingredient?->display_name ?? 'Ingredient',
                        'percentage' => (float) $item->percentage,
                        'quantity' => $quantity,
                        'unit' => $unit,
                        'price_per_kg' => $pricePerKg,
                        'line_cost' => $lineCost,
                        'is_unpriced' => $pricePerKg === null,
                    ];
                }))
            ->values()
            ->all();

        $packagingPricesByKey = $costing->packagingItems
            ->keyBy(fn (RecipeVersionCostingPackagingItem $item): string => $this->packagingKey(
                $item->user_packaging_item_id === null ? null : (int) $item->user_packaging_item_id,
                $item->name,
            ));

        $packagingRows = $version->packagingItems
            ->map(function (RecipeVersionPackagingItem $item) use ($packagingPricesByKey, $unitsProduced): array {
                $priceRow = $packagingPricesByKey->get($this->packagingKey(
                    $item->user_packaging_item_id === null ? null : (int) $item->user_packaging_item_id,
                    $item->name,
                ));
                $unitCost = $priceRow?->unit_cost ?? $item->packagingItem?->unit_cost;
                $unitCost = $unitCost === null ? null : (float) $unitCost;
                $componentsPerUnit = $priceRow?->quantity === null ? (float) $item->components_per_unit : (float) $priceRow->quantity;
                $costPerFinishedUnit = $unitCost === null ? 0.0 : round($unitCost * $componentsPerUnit, 4);

                return [
                    'user_packaging_item_id' => $item->user_packaging_item_id,
                    'position' => (int) $item->position,
                    'name' => $item->name,
                    'components_per_unit' => $componentsPerUnit,
                    'unit_cost' => $unitCost,
                    'cost_per_finished_unit' => $costPerFinishedUnit,
                    'line_cost' => $unitsProduced !== null ? round($costPerFinishedUnit * $unitsProduced, 4) : 0.0,
                    'is_unpriced' => $unitCost === null,
                ];
            })
            ->values()
            ->all();

        $ingredientTotal = round(collect($ingredientRows)->sum(fn (array $row): float => (float) $row['line_cost']), 4);
        $packagingTotal = round(collect($packagingRows)->sum(fn (array $row): float => (float) $row['line_cost']), 4);
        $totalCost = round($ingredientTotal + $packagingTotal, 4);

        return [
            'currency' => $currency,
            'ingredient_rows' => $ingredientRows,
            'packaging_rows' => $packagingRows,
            'ingredient_total' => $ingredientTotal,
            'packaging_total' => $packagingTotal,
            'total_cost' => $totalCost,
            'cost_per_unit' => $unitsProduced !== null && $unitsProduced > 0 ? round($totalCost / $unitsProduced, 4) : 0.0,
            'has_unpriced_rows' => collect($ingredientRows)->contains(fn (array $row): bool => $row['is_unpriced'])
                || collect($packagingRows)->contains(fn (array $row): bool => $row['is_unpriced']),
        ];
    }

    public function lotKey(int $ingredientId, string $phaseKey, int $position): string
    {
        return implode(':', [$ingredientId, $phaseKey, $position]);
    }

    private function costingKey(int $ingredientId, string $phaseKey, int $position): string
    {
        return implode(':', [$ingredientId, $phaseKey, $position]);
    }

    private function packagingKey(?int $packagingItemId, string $name): string
    {
        return ($packagingItemId ?? 'unlinked').':'.mb_strtolower($name);
    }
}
```

- [ ] **Step 5: Wire the preview builder into the saved formula view data**

Modify `app/Services/RecipeVersionViewDataBuilder.php`:

```php
public function __construct(
    private readonly RecipeWorkbenchService $recipeWorkbenchService,
    private readonly RecipeVersionCostPreviewBuilder $costPreviewBuilder,
) {}
```

Replace the private `costingData()` method with an implementation that calls `RecipeVersionCostPreviewBuilder::build()`, formats the existing summary labels, and preserves the existing return keys:

```php
private function costingData(Recipe $recipe, RecipeVersion $version, float $selectedOilWeight, array $batchContext): array
{
    $user = $recipe->createdBy ?? $recipe->ownerUser();

    if (! $user instanceof \App\Models\User) {
        return [
            'summary' => [],
            'ingredientRows' => [],
            'packagingRows' => [],
            'currency' => 'EUR',
            'hasCostingData' => false,
            'hasUnpricedRows' => false,
        ];
    }

    $unitsProduced = $this->positiveInt($batchContext['units_produced'] ?? null);
    $preview = $this->costPreviewBuilder->build($recipe, $version, $user, $selectedOilWeight, $unitsProduced);
    $currency = $preview['currency'];

    return [
        'summary' => [
            ['label' => 'Ingredient total', 'value' => $this->money($preview['ingredient_total'], $currency)],
            ['label' => 'Packaging total', 'value' => $unitsProduced !== null ? $this->money($preview['packaging_total'], $currency) : 'Set units produced'],
            ['label' => 'Total batch cost', 'value' => $this->money($preview['total_cost'], $currency)],
            ['label' => 'Cost per unit', 'value' => $unitsProduced !== null && $unitsProduced > 0 ? $this->money($preview['cost_per_unit'], $currency) : 'Not set'],
        ],
        'ingredientRows' => collect($preview['ingredient_rows'])
            ->map(fn (array $row): array => [
                'phase' => $row['phase_name'],
                'name' => $row['ingredient_name'],
                'weight' => $row['quantity'],
                'price_per_kg' => $row['price_per_kg'],
                'line_cost' => $row['line_cost'],
            ])
            ->all(),
        'packagingRows' => collect($preview['packaging_rows'])
            ->map(fn (array $row): array => [
                'name' => $row['name'],
                'unit_cost' => $row['unit_cost'],
                'quantity' => $row['components_per_unit'],
                'cost_per_finished_unit' => $row['cost_per_finished_unit'],
                'line_cost' => $unitsProduced !== null ? $row['line_cost'] : null,
            ])
            ->all(),
        'currency' => $currency,
        'hasCostingData' => true,
        'hasUnpricedRows' => $preview['has_unpriced_rows'],
    ];
}
```

If `Recipe::ownerUser()` does not exist, add this method to `app/Models/Recipe.php`:

```php
public function ownerUser(): ?User
{
    return User::query()->find($this->owner_id);
}
```

- [ ] **Step 6: Run the preview and existing costing tests**

Run:

```bash
php artisan test --compact --filter="builds numeric production cost preview rows"
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php
```

Expected: both commands PASS.

- [ ] **Step 7: Format and commit**

Run:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter="builds numeric production cost preview rows"
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php
git add app/Services/RecipeVersionCostPreviewBuilder.php app/Services/RecipeVersionViewDataBuilder.php app/Models/Recipe.php tests/Feature/ProductionSnapshotsTest.php
git commit -m "feat: build production cost previews"
```

Expected: Pint reports clean/fixed files, tests pass, and commit succeeds.

---

### Task 3: Production Snapshot Recording Service

**Files:**
- Create: `app/Services/ProductionSnapshotService.php`
- Modify: `tests/Feature/ProductionSnapshotsTest.php`

- [ ] **Step 1: Write failing service tests for soap snapshots and frozen prices**

Append this test to `tests/Feature/ProductionSnapshotsTest.php`:

```php
use App\Models\ProductionBatch;
use App\Services\ProductionSnapshotService;

it('records a soap production snapshot and freezes current prices', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);

    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-010',
        'manufacture_date' => '2026-06-10',
        'batch_basis' => 1500,
        'units_produced' => 12,
        'production_notes' => 'Reached medium trace.',
        'ingredient_lot_numbers' => [
            "{$ingredient->id}:saponified_oils:1" => 'OO-LOT-10',
        ],
    ]);

    expect($batch)->toBeInstanceOf(ProductionBatch::class)
        ->and($batch->recipe_name)->toBe('Snapshot Soap')
        ->and($batch->product_family_slug)->toBe('soap')
        ->and($batch->production_batch_number)->toBe('B-2026-010')
        ->and($batch->batch_basis_label)->toBe('Oil quantity')
        ->and($batch->batch_basis_value)->toBe('1500.000')
        ->and($batch->batch_basis_unit)->toBe('g')
        ->and($batch->units_produced)->toBe(12)
        ->and($batch->ingredient_total)->toBe('12.7500')
        ->and($batch->packaging_total)->toBe('3.0000')
        ->and($batch->total_cost)->toBe('15.7500')
        ->and($batch->cost_per_unit)->toBe('1.3125')
        ->and($batch->ingredients)->toHaveCount(1)
        ->and($batch->ingredients->first()->ingredient_name)->toBe('Olive Oil')
        ->and($batch->ingredients->first()->quantity)->toBe('1500.0000')
        ->and($batch->ingredients->first()->ingredient_lot_number)->toBe('OO-LOT-10')
        ->and($batch->packagingItems)->toHaveCount(1)
        ->and($batch->packagingItems->first()->name)->toBe('Soap box')
        ->and($batch->production_notes)->toBe('Reached medium trace.');

    $batch->ingredients->first()->update([
        'price_per_kg' => 99,
    ]);

    expect($batch->fresh()->ingredients->first()->price_per_kg)->toBe('99.0000');
});
```

- [ ] **Step 2: Add costing helper**

Append this helper to `tests/Feature/ProductionSnapshotsTest.php`:

```php
function productionSnapshotAttachCosting(User $user, RecipeVersion $version, Ingredient $ingredient, float $ingredientPrice, float $packagingPrice): void
{
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Soap box',
        'unit_cost' => $packagingPrice,
        'currency' => 'EUR',
    ]);

    $version->packagingItems()->create([
        'user_packaging_item_id' => $packagingItem->id,
        'name' => 'Soap box',
        'components_per_unit' => 1,
        'position' => 1,
    ]);

    $costing = RecipeVersionCosting::query()->create([
        'recipe_version_id' => $version->id,
        'user_id' => $user->id,
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 10,
        'currency' => 'EUR',
    ]);

    RecipeVersionCostingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'ingredient_id' => $ingredient->id,
        'phase_key' => 'saponified_oils',
        'position' => 1,
        'price_per_kg' => $ingredientPrice,
    ]);

    RecipeVersionCostingPackagingItem::query()->create([
        'recipe_version_costing_id' => $costing->id,
        'user_packaging_item_id' => $packagingItem->id,
        'name' => 'Soap box',
        'unit_cost' => $packagingPrice,
        'quantity' => 1,
    ]);
}
```

- [ ] **Step 3: Run failing service test**

Run:

```bash
php artisan test --compact --filter="records a soap production snapshot"
```

Expected: FAIL because `ProductionSnapshotService` does not exist.

- [ ] **Step 4: Implement recording service**

Create `app/Services/ProductionSnapshotService.php`:

```php
<?php

namespace App\Services;

use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProductionSnapshotService
{
    public function __construct(
        private readonly RecipeVersionCostPreviewBuilder $costPreviewBuilder,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function preview(Recipe $recipe, RecipeVersion $version, User $user, array $input): array
    {
        $batchBasis = $this->positiveFloat($input['batch_basis'] ?? $version->batch_size);
        $unitsProduced = $this->positiveInt($input['units_produced'] ?? null);

        return [
            ...$this->costPreviewBuilder->build($recipe, $version, $user, $batchBasis, $unitsProduced),
            'batch_basis_label' => $this->batchBasisLabel($recipe),
            'batch_basis_value' => $batchBasis,
            'batch_basis_unit' => $version->batch_unit ?: 'g',
            'units_produced' => $unitsProduced,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function record(Recipe $recipe, RecipeVersion $version, User $user, array $input): ProductionBatch
    {
        $preview = $this->preview($recipe, $version, $user, $input);
        $lotNumbers = collect(is_array($input['ingredient_lot_numbers'] ?? null) ? $input['ingredient_lot_numbers'] : [])
            ->map(fn (mixed $value): ?string => filled($value) ? trim((string) $value) : null);

        return DB::transaction(function () use ($input, $lotNumbers, $preview, $recipe, $user, $version): ProductionBatch {
            $batch = ProductionBatch::query()->create([
                'user_id' => $user->id,
                'recipe_id' => $recipe->id,
                'recipe_version_id' => $version->id,
                'recipe_name' => $recipe->name,
                'recipe_version_number' => (int) $version->version_number,
                'product_family_slug' => $recipe->productFamily?->slug ?? 'soap',
                'production_batch_number' => $this->nullableString($input['production_batch_number'] ?? null),
                'manufacture_date' => (string) $input['manufacture_date'],
                'batch_basis_label' => $preview['batch_basis_label'],
                'batch_basis_value' => $preview['batch_basis_value'],
                'batch_basis_unit' => $preview['batch_basis_unit'],
                'units_produced' => (int) $preview['units_produced'],
                'currency' => $preview['currency'],
                'ingredient_total' => $preview['ingredient_total'],
                'packaging_total' => $preview['packaging_total'],
                'total_cost' => $preview['total_cost'],
                'cost_per_unit' => $preview['cost_per_unit'],
                'production_notes' => $this->nullableString($input['production_notes'] ?? null),
            ]);

            collect($preview['ingredient_rows'])->each(function (array $row) use ($batch, $lotNumbers): void {
                $batch->ingredients()->create([
                    'ingredient_id' => $row['ingredient_id'],
                    'raw_material_lot_id' => null,
                    'phase_key' => $row['phase_key'],
                    'phase_name' => $row['phase_name'],
                    'position' => $row['position'],
                    'ingredient_name' => $row['ingredient_name'],
                    'percentage' => $row['percentage'],
                    'quantity' => $row['quantity'],
                    'unit' => $row['unit'],
                    'price_per_kg' => $row['price_per_kg'],
                    'line_cost' => $row['line_cost'],
                    'ingredient_lot_number' => $lotNumbers->get($row['lot_key']),
                ]);
            });

            collect($preview['packaging_rows'])->each(function (array $row) use ($batch): void {
                $batch->packagingItems()->create([
                    'user_packaging_item_id' => $row['user_packaging_item_id'],
                    'position' => $row['position'],
                    'name' => $row['name'],
                    'components_per_unit' => $row['components_per_unit'],
                    'unit_cost' => $row['unit_cost'] ?? 0,
                    'cost_per_finished_unit' => $row['cost_per_finished_unit'],
                    'line_cost' => $row['line_cost'],
                ]);
            });

            return $batch->fresh(['ingredients', 'packagingItems']) ?? $batch->load(['ingredients', 'packagingItems']);
        });
    }

    private function batchBasisLabel(Recipe $recipe): string
    {
        return $recipe->productFamily?->calculation_basis === 'total_formula'
            ? 'Total batch quantity'
            : 'Oil quantity';
    }

    private function positiveFloat(mixed $value): float
    {
        $normalized = is_numeric($value) ? round((float) $value, 3) : 0.0;

        return $normalized > 0 ? $normalized : 0.0;
    }

    private function positiveInt(mixed $value): ?int
    {
        $normalized = is_numeric($value) ? (int) $value : 0;

        return $normalized > 0 ? $normalized : null;
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
```

- [ ] **Step 5: Run service test**

Run:

```bash
php artisan test --compact --filter="records a soap production snapshot"
```

Expected: PASS.

- [ ] **Step 6: Add and pass cosmetic snapshot test**

Append this test:

```php
it('records a cosmetic production snapshot using total batch quantity language', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotCosmeticRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 20, packagingPrice: 0.4);

    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => null,
        'manufacture_date' => '2026-06-11',
        'batch_basis' => 500,
        'units_produced' => 5,
        'production_notes' => '',
        'ingredient_lot_numbers' => [],
    ]);

    expect($batch->batch_basis_label)->toBe('Total batch quantity')
        ->and($batch->batch_basis_value)->toBe('500.000')
        ->and($batch->ingredients->first()->quantity)->toBe('500.0000')
        ->and($batch->total_cost)->toBe('12.0000')
        ->and($batch->cost_per_unit)->toBe('2.4000');
});
```

Add this helper:

```php
/**
 * @return array{0: User, 1: Recipe, 2: RecipeVersion, 3: Ingredient}
 */
function productionSnapshotCosmeticRecipe(): array
{
    $user = User::factory()->create();
    $cosmeticFamily = ProductFamily::factory()->create([
        'slug' => 'cosmetic',
        'name' => 'Cosmetic',
        'calculation_basis' => 'total_formula',
    ]);
    $productType = ProductType::factory()->create([
        'product_family_id' => $cosmeticFamily->id,
        'name' => 'Cream / lotion',
        'slug' => 'cream-lotion',
    ]);
    $ingredient = productionSnapshotIngredient();

    $service = app(RecipeWorkbenchService::class);
    $draftVersion = $service->saveDraft(
        $user,
        $cosmeticFamily,
        productionSnapshotCosmeticDraftPayload($ingredient, $productType, 'Snapshot Lotion'),
    );

    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $service->saveAsNewVersion(
        $user,
        $cosmeticFamily,
        productionSnapshotCosmeticDraftPayload($ingredient, $productType, 'Snapshot Lotion'),
        $recipe,
    );

    $version = RecipeVersion::withoutGlobalScopes()
        ->where('recipe_id', $recipe->id)
        ->where('is_draft', false)
        ->latest('version_number')
        ->firstOrFail();

    return [$user, $recipe, $version, $ingredient];
}

/**
 * @return array<string, mixed>
 */
function productionSnapshotCosmeticDraftPayload(Ingredient $ingredient, ProductType $productType, string $name): array
{
    return [
        'name' => $name,
        'product_type_id' => $productType->id,
        'oil_unit' => 'g',
        'oil_weight' => 500,
        'manufacturing_mode' => 'blend_only',
        'exposure_mode' => 'leave_on',
        'regulatory_regime' => 'eu',
        'editing_mode' => 'percentage',
        'ifra_product_category_id' => null,
        'phases' => [
            [
                'key' => 'saponified_oils',
                'name' => 'Phase A',
            ],
        ],
        'phase_items' => [
            'saponified_oils' => [
                [
                    'ingredient_id' => $ingredient->id,
                    'percentage' => 100,
                    'weight' => 500,
                    'note' => null,
                ],
            ],
        ],
    ];
}
```

Run:

```bash
php artisan test --compact --filter="records a cosmetic production snapshot"
```

Expected: PASS.

- [ ] **Step 7: Format and commit**

Run:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/ProductionSnapshotsTest.php
git add app/Services/ProductionSnapshotService.php tests/Feature/ProductionSnapshotsTest.php
git commit -m "feat: record production snapshots"
```

Expected: all production snapshot tests pass and commit succeeds.

---

### Task 4: Routes, Requests, And Controller

**Files:**
- Create: `app/Http/Requests/StoreProductionBatchRequest.php`
- Create: `app/Http/Requests/UpdateProductionBatchAnnotationsRequest.php`
- Create: `app/Http/Controllers/ProductionBatchController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/ProductionSnapshotsTest.php`

- [ ] **Step 1: Scaffold request and controller files**

Run:

```bash
php artisan make:request StoreProductionBatchRequest --no-interaction
php artisan make:request UpdateProductionBatchAnnotationsRequest --no-interaction
php artisan make:controller ProductionBatchController --no-interaction
```

Expected: Artisan creates the three files.

- [ ] **Step 2: Write failing controller test for storing a production batch**

Append this test:

```php
it('stores a production snapshot from the saved formula route', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);

    $this->actingAs($user)
        ->post(route('recipes.production-batches.store', $recipe), [
            'production_batch_number' => 'B-2026-020',
            'manufacture_date' => '2026-06-12',
            'batch_basis' => 1500,
            'units_produced' => 12,
            'production_notes' => 'QC: pH checked.',
            'ingredient_lot_numbers' => [
                "{$ingredient->id}:saponified_oils:1" => 'OO-LOT-20',
            ],
        ])
        ->assertRedirect();

    $batch = ProductionBatch::query()->firstOrFail();

    expect($batch->production_batch_number)->toBe('B-2026-020')
        ->and($batch->production_notes)->toBe('QC: pH checked.')
        ->and($batch->ingredients->first()->ingredient_lot_number)->toBe('OO-LOT-20');
});
```

- [ ] **Step 3: Run failing controller test**

Run:

```bash
php artisan test --compact --filter="stores a production snapshot from the saved formula route"
```

Expected: FAIL because the route and controller action do not exist.

- [ ] **Step 4: Implement form requests**

Replace `app/Http/Requests/StoreProductionBatchRequest.php` with:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductionBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'production_batch_number' => ['nullable', 'string', 'max:120'],
            'manufacture_date' => ['required', 'date'],
            'batch_basis' => ['required', 'numeric', 'gt:0'],
            'units_produced' => ['required', 'integer', 'min:1'],
            'production_notes' => ['nullable', 'string', 'max:10000'],
            'ingredient_lot_numbers' => ['array'],
            'ingredient_lot_numbers.*' => ['nullable', 'string', 'max:120'],
        ];
    }
}
```

Replace `app/Http/Requests/UpdateProductionBatchAnnotationsRequest.php` with:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductionBatchAnnotationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'production_batch_number' => ['nullable', 'string', 'max:120'],
            'production_notes' => ['nullable', 'string', 'max:10000'],
            'ingredient_lot_numbers' => ['array'],
            'ingredient_lot_numbers.*' => ['nullable', 'string', 'max:120'],
        ];
    }
}
```

- [ ] **Step 5: Implement controller**

Replace `app/Http/Controllers/ProductionBatchController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductionBatchRequest;
use App\Http\Requests\UpdateProductionBatchAnnotationsRequest;
use App\Models\ProductionBatch;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Services\CurrentAppUserResolver;
use App\Services\ProductionSnapshotService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ProductionBatchController extends Controller
{
    public function store(
        int $recipe,
        StoreProductionBatchRequest $request,
        CurrentAppUserResolver $currentAppUserResolver,
        ProductionSnapshotService $productionSnapshotService,
    ): RedirectResponse {
        $user = $currentAppUserResolver->resolve();

        abort_unless($user !== null, 403);

        $recipe = Recipe::withoutGlobalScopes()->with('productFamily')->findOrFail($recipe);

        abort_unless($recipe->owner_id === $user->id && $recipe->isAccessibleBy($user), 404);

        $version = RecipeVersion::withoutGlobalScopes()
            ->where('recipe_id', $recipe->id)
            ->where('is_draft', false)
            ->orderByDesc('version_number')
            ->firstOrFail();

        $batch = $productionSnapshotService->record($recipe, $version, $user, $request->validated());

        return redirect()
            ->route('production-batches.show', $batch)
            ->with('status', 'Production recorded.');
    }

    public function show(ProductionBatch $productionBatch): View
    {
        $this->authorize('view', $productionBatch);

        return view('production-batches.show', [
            'productionBatch' => $productionBatch->load(['recipe', 'recipeVersion', 'ingredients', 'packagingItems']),
        ]);
    }

    public function update(ProductionBatch $productionBatch, UpdateProductionBatchAnnotationsRequest $request): RedirectResponse
    {
        $this->authorize('update', $productionBatch);

        $validated = $request->validated();
        $lotNumbers = collect(is_array($validated['ingredient_lot_numbers'] ?? null) ? $validated['ingredient_lot_numbers'] : []);

        $productionBatch->update([
            'production_batch_number' => filled($validated['production_batch_number'] ?? null) ? trim((string) $validated['production_batch_number']) : null,
            'production_notes' => filled($validated['production_notes'] ?? null) ? trim((string) $validated['production_notes']) : null,
        ]);

        $productionBatch->ingredients()->get()->each(function ($ingredient) use ($lotNumbers): void {
            $key = implode(':', [$ingredient->ingredient_id, $ingredient->phase_key, $ingredient->position]);
            $value = $lotNumbers->get($key);

            $ingredient->update([
                'ingredient_lot_number' => filled($value) ? trim((string) $value) : null,
            ]);
        });

        return redirect()
            ->route('production-batches.show', $productionBatch)
            ->with('status', 'Production notes updated.');
    }

    public function print(ProductionBatch $productionBatch): View
    {
        $this->authorize('view', $productionBatch);

        return view('production-batches.print', [
            'productionBatch' => $productionBatch->load(['ingredients', 'packagingItems']),
        ]);
    }
}
```

- [ ] **Step 6: Add routes**

Modify `routes/web.php`:

```php
use App\Http\Controllers\ProductionBatchController;
```

Inside the existing `/dashboard/recipes` route group, add:

```php
Route::post('/{recipe}/production-batches', [ProductionBatchController::class, 'store'])->name('production-batches.store');
```

After the recipe route group, add:

```php
Route::controller(ProductionBatchController::class)
    ->prefix('/dashboard/production-batches')
    ->name('production-batches.')
    ->group(function (): void {
        Route::get('/{productionBatch}', 'show')->name('show');
        Route::patch('/{productionBatch}', 'update')->name('update');
        Route::get('/{productionBatch}/print', 'print')->name('print');
    });
```

- [ ] **Step 7: Create temporary minimal views so routes render**

Create `resources/views/production-batches/show.blade.php`:

```blade
@extends('layouts.app-shell')

@section('title', $productionBatch->recipe_name.' · Production · '.config('app.name'))
@section('page_heading', 'Production')

@section('content')
    <div class="mx-auto max-w-[90rem] space-y-6">
        <h1>{{ $productionBatch->recipe_name }}</h1>
        <p>{{ $productionBatch->production_batch_number ?: 'No batch number' }}</p>
    </div>
@endsection
```

Create `resources/views/production-batches/print.blade.php`:

```blade
@extends('layouts.print')

@section('title', $productionBatch->recipe_name.' · Production Print · '.config('app.name'))

@section('content')
    <article class="document-sheet border border-slate-300 bg-white p-6 print:border-0 print:p-0">
        <h1>{{ $productionBatch->recipe_name }}</h1>
    </article>
@endsection
```

- [ ] **Step 8: Run controller test**

Run:

```bash
php artisan test --compact --filter="stores a production snapshot from the saved formula route"
```

Expected: PASS.

- [ ] **Step 9: Add authorization and validation tests**

Append these tests:

```php
it('does not allow another user to view a production snapshot', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-030',
        'manufacture_date' => '2026-06-12',
        'batch_basis' => 1000,
        'units_produced' => 10,
    ]);

    $this->actingAs(User::factory()->create())
        ->get(route('production-batches.show', $batch))
        ->assertForbidden();
});

it('validates required production recording fields', function (): void {
    [$user, $recipe] = productionSnapshotSoapRecipe();

    $this->actingAs($user)
        ->post(route('recipes.production-batches.store', $recipe), [
            'manufacture_date' => '',
            'batch_basis' => 0,
            'units_produced' => 0,
        ])
        ->assertSessionHasErrors(['manufacture_date', 'batch_basis', 'units_produced']);
});
```

Run:

```bash
php artisan test --compact --filter="production snapshot"
```

Expected: PASS for the production snapshot tests.

- [ ] **Step 10: Format and commit**

Run:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/ProductionSnapshotsTest.php
git add app/Http/Requests/StoreProductionBatchRequest.php app/Http/Requests/UpdateProductionBatchAnnotationsRequest.php app/Http/Controllers/ProductionBatchController.php routes/web.php resources/views/production-batches/show.blade.php resources/views/production-batches/print.blade.php tests/Feature/ProductionSnapshotsTest.php
git commit -m "feat: add production snapshot routes"
```

Expected: tests pass and commit succeeds.

---

### Task 5: Saved Formula Record Production UI And History

**Files:**
- Modify: `app/Http/Controllers/RecipeController.php`
- Modify: `resources/views/recipes/version.blade.php`
- Modify: `tests/Feature/RecipeVersionPackagingPlanTest.php`
- Modify: `tests/Feature/ProductionSnapshotsTest.php`

- [ ] **Step 1: Write failing saved page UI test**

Append this test to `tests/Feature/ProductionSnapshotsTest.php`:

```php
it('shows record production controls on the saved formula page', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);

    $this->actingAs($user)
        ->get(route('recipes.saved', ['recipe' => $recipe->id]))
        ->assertSuccessful()
        ->assertSee('Record production')
        ->assertSee('Production batch number')
        ->assertSee('Manufacture date')
        ->assertSee('Oil quantity')
        ->assertSee('Units produced')
        ->assertSee('Ingredient lot numbers')
        ->assertSee('Production notes')
        ->assertSee('Ingredient cost')
        ->assertSee('Packaging cost')
        ->assertSee('Cost per finished unit')
        ->assertSee('Record production')
        ->assertDontSee('Prepare batch');
});
```

- [ ] **Step 2: Run failing UI test**

Run:

```bash
php artisan test --compact --filter="shows record production controls"
```

Expected: FAIL because the saved formula page still renders Prepare batch.

- [ ] **Step 3: Pass production preview and history data from controller**

Modify `RecipeController::saved()` to inject `ProductionSnapshotService` and append production data:

```php
public function saved(
    int $recipe,
    Request $request,
    CurrentAppUserResolver $currentAppUserResolver,
    RecipeVersionViewDataBuilder $recipeVersionViewDataBuilder,
    \App\Services\ProductionSnapshotService $productionSnapshotService,
): View {
    [$recipe, $savedFormula] = $this->accessibleCurrentSavedFormula($recipe, $currentAppUserResolver);
    $user = $currentAppUserResolver->resolve();
    $viewData = $recipeVersionViewDataBuilder->build($recipe, $savedFormula, $request->query('oil_weight'), $request->query());
    $canRecordProduction = $user !== null && $recipe->owner_id === $user->id;

    return view('recipes.version', [
        ...$viewData,
        'canRecordProduction' => $canRecordProduction,
        'productionPreview' => $canRecordProduction
            ? $productionSnapshotService->preview($recipe, $savedFormula, $user, [
                'batch_basis' => $viewData['batchContext']['batch_basis'],
                'units_produced' => $viewData['batchContext']['units_produced'],
            ])
            : null,
        'productionBatches' => $canRecordProduction
            ? $recipe->productionBatches()
                ->where('user_id', $user->id)
                ->latest('manufacture_date')
                ->latest('id')
                ->limit(8)
                ->get()
            : collect(),
    ]);
}
```

- [ ] **Step 4: Replace Prepare batch panel with Record production**

In `resources/views/recipes/version.blade.php`, replace the current Prepare batch card that starts with `<form method="GET"` with this POST form:

```blade
@if ($canRecordProduction)
    <form method="POST" action="{{ route('recipes.production-batches.store', ['recipe' => $recipe->id]) }}" class="sk-card p-5">
        @csrf
        <p class="sk-eyebrow">Production</p>
        <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Record production</h2>

        @if (($productionPreview['has_unpriced_rows'] ?? false) === true)
            <div class="mt-3 rounded-lg border border-[var(--color-warning-soft)] bg-[var(--color-warning-soft)]/35 px-3 py-2 text-sm text-[var(--color-ink-soft)]">
                Some rows are unpriced. Missing prices will be treated as zero.
            </div>
        @endif

        <label class="mt-4 block">
            <span class="text-sm font-medium text-[var(--color-ink-strong)]">Production batch number</span>
            <input name="production_batch_number" value="{{ old('production_batch_number', $batchContext['batch_number']) }}" type="text" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
            @error('production_batch_number') <p class="mt-1 text-sm text-[var(--color-danger)]">{{ $message }}</p> @enderror
        </label>

        <label class="mt-3 block">
            <span class="text-sm font-medium text-[var(--color-ink-strong)]">Manufacture date</span>
            <input name="manufacture_date" value="{{ old('manufacture_date', $batchContext['manufacture_date']) }}" type="date" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
            @error('manufacture_date') <p class="mt-1 text-sm text-[var(--color-danger)]">{{ $message }}</p> @enderror
        </label>

        <label class="mt-3 block">
            <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ $productionPreview['batch_basis_label'] ?? 'Oil quantity' }}</span>
            <div class="mt-2 flex items-center gap-2">
                <input name="batch_basis" value="{{ old('batch_basis', $batchContext['batch_basis']) }}" type="text" inputmode="decimal" class="numeric w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                <span class="numeric rounded-full border border-[var(--color-line)] bg-white px-3 py-1.5 text-xs font-medium text-[var(--color-ink-soft)]">{{ $productionPreview['batch_basis_unit'] ?? ($snapshot['draft']['oilUnit'] ?? 'g') }}</span>
            </div>
            @error('batch_basis') <p class="mt-1 text-sm text-[var(--color-danger)]">{{ $message }}</p> @enderror
        </label>

        <label class="mt-3 block">
            <span class="text-sm font-medium text-[var(--color-ink-strong)]">Units produced</span>
            <input name="units_produced" value="{{ old('units_produced', $batchContext['units_produced']) }}" type="text" inputmode="numeric" class="numeric mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
            @error('units_produced') <p class="mt-1 text-sm text-[var(--color-danger)]">{{ $message }}</p> @enderror
        </label>

        <div class="mt-4">
            <p class="text-sm font-medium text-[var(--color-ink-strong)]">Ingredient lot numbers</p>
            <div class="mt-2 overflow-hidden rounded-lg border border-[var(--color-line)]">
                <table class="w-full text-left text-sm">
                    <thead class="bg-[var(--color-panel)] text-xs uppercase text-[var(--color-ink-soft)]">
                        <tr>
                            <th class="px-3 py-2">Ingredient</th>
                            <th class="numeric px-3 py-2">Quantity</th>
                            <th class="px-3 py-2">Ingredient lot number</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)] bg-white">
                        @foreach (($productionPreview['ingredient_rows'] ?? []) as $row)
                            <tr>
                                <td class="px-3 py-2 font-medium text-[var(--color-ink-strong)]">{{ $row['ingredient_name'] }}</td>
                                <td class="numeric px-3 py-2 text-[var(--color-ink-soft)]">{{ rtrim(rtrim(number_format((float) $row['quantity'], 2, '.', ''), '0'), '.') }} {{ $row['unit'] }}</td>
                                <td class="px-3 py-2">
                                    <input name="ingredient_lot_numbers[{{ $row['lot_key'] }}]" value="{{ old('ingredient_lot_numbers.'.$row['lot_key']) }}" type="text" class="w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <label class="mt-4 block">
            <span class="text-sm font-medium text-[var(--color-ink-strong)]">Production notes</span>
            <textarea name="production_notes" rows="7" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]">{{ old('production_notes') }}</textarea>
            @error('production_notes') <p class="mt-1 text-sm text-[var(--color-danger)]">{{ $message }}</p> @enderror
        </label>

        <div class="mt-4 grid gap-2 text-sm">
            <div class="flex justify-between gap-3"><span>Ingredient cost</span><span class="numeric">{{ rtrim(rtrim(number_format((float) ($productionPreview['ingredient_total'] ?? 0), 2, '.', ''), '0'), '.') }} {{ $productionPreview['currency'] ?? 'EUR' }}</span></div>
            <div class="flex justify-between gap-3"><span>Packaging cost</span><span class="numeric">{{ rtrim(rtrim(number_format((float) ($productionPreview['packaging_total'] ?? 0), 2, '.', ''), '0'), '.') }} {{ $productionPreview['currency'] ?? 'EUR' }}</span></div>
            <div class="flex justify-between gap-3 font-semibold text-[var(--color-ink-strong)]"><span>Total production cost</span><span class="numeric">{{ rtrim(rtrim(number_format((float) ($productionPreview['total_cost'] ?? 0), 2, '.', ''), '0'), '.') }} {{ $productionPreview['currency'] ?? 'EUR' }}</span></div>
            <div class="flex justify-between gap-3"><span>Cost per finished unit</span><span class="numeric">{{ rtrim(rtrim(number_format((float) ($productionPreview['cost_per_unit'] ?? 0), 4, '.', ''), '0'), '.') }} {{ $productionPreview['currency'] ?? 'EUR' }}</span></div>
        </div>

        <button type="submit" class="mt-4 rounded-full bg-[var(--color-ink-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">
            Record production
        </button>
    </form>
@endif
```

- [ ] **Step 5: Add production history below the form**

Add this section after the two-column packaging/production grid:

```blade
@if ($canRecordProduction)
    <section class="sk-card overflow-hidden">
        <div class="border-b border-[var(--color-line)] px-5 py-4">
            <p class="sk-eyebrow">Production history</p>
            <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Production history</h2>
        </div>

        @if ($productionBatches->isNotEmpty())
            <div class="divide-y divide-[var(--color-line)]">
                @foreach ($productionBatches as $productionBatch)
                    <div class="flex flex-col gap-3 px-5 py-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <p class="font-medium text-[var(--color-ink-strong)]">{{ $productionBatch->manufacture_date->format('Y-m-d') }} · {{ $productionBatch->production_batch_number ?: 'No batch number' }}</p>
                            <p class="mt-1 text-xs text-[var(--color-ink-soft)]">{{ $productionBatch->batch_basis_label }} {{ rtrim(rtrim(number_format((float) $productionBatch->batch_basis_value, 2, '.', ''), '0'), '.') }} {{ $productionBatch->batch_basis_unit }} · {{ $productionBatch->units_produced }} units · {{ rtrim(rtrim(number_format((float) $productionBatch->cost_per_unit, 4, '.', ''), '0'), '.') }} {{ $productionBatch->currency }}/unit</p>
                        </div>
                        <div class="flex gap-2">
                            <a href="{{ route('production-batches.show', $productionBatch) }}" class="rounded-full border border-[var(--color-line)] px-3 py-1.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">View</a>
                            <a href="{{ route('production-batches.print', $productionBatch) }}" class="rounded-full border border-[var(--color-line)] px-3 py-1.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">Print</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="px-5 py-6 text-sm text-[var(--color-ink-soft)]">
                No production batches recorded yet.
            </div>
        @endif
    </section>
@endif
```

- [ ] **Step 6: Add history visibility test**

Append this test:

```php
it('shows production history only for the production batch owner', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-040',
        'manufacture_date' => '2026-06-13',
        'batch_basis' => 1000,
        'units_produced' => 10,
    ]);

    $this->actingAs($user)
        ->get(route('recipes.saved', $recipe))
        ->assertSuccessful()
        ->assertSee('Production history')
        ->assertSee('B-2026-040')
        ->assertSee(route('production-batches.show', $batch), false);

    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get(route('recipes.saved', $recipe))
        ->assertNotFound();
});
```

- [ ] **Step 7: Run UI tests**

Run:

```bash
php artisan test --compact --filter="shows record production controls"
php artisan test --compact --filter="shows production history only"
php artisan test --compact tests/Feature/ProductionSnapshotsTest.php
php artisan test --compact tests/Feature/RecipeVersionPackagingPlanTest.php
```

Expected: PASS.

- [ ] **Step 8: Format and commit**

Run:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/ProductionSnapshotsTest.php
php artisan test --compact tests/Feature/RecipeVersionPackagingPlanTest.php
npm run build
git add app/Http/Controllers/RecipeController.php resources/views/recipes/version.blade.php tests/Feature/RecipeVersionPackagingPlanTest.php tests/Feature/ProductionSnapshotsTest.php public
git commit -m "feat: add record production form"
```

Expected: tests pass, Vite build succeeds, and commit succeeds.

---

### Task 6: Production Snapshot Show Page, Editable Notes, And Printout

**Files:**
- Modify: `resources/views/production-batches/show.blade.php`
- Modify: `resources/views/production-batches/print.blade.php`
- Modify: `tests/Feature/ProductionSnapshotsTest.php`

- [ ] **Step 1: Write failing show/update/print tests**

Append these tests:

```php
it('shows a read-only production snapshot with editable annotations', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-050',
        'manufacture_date' => '2026-06-14',
        'batch_basis' => 1000,
        'units_produced' => 10,
        'production_notes' => 'Initial notes',
    ]);

    $this->actingAs($user)
        ->get(route('production-batches.show', $batch))
        ->assertSuccessful()
        ->assertSee('Production snapshot')
        ->assertSee('B-2026-050')
        ->assertSee('Oil quantity')
        ->assertSee('1000')
        ->assertSee('Olive Oil')
        ->assertSee('Soap box')
        ->assertSee('Initial notes')
        ->assertSee('Production notes')
        ->assertSee('Save notes')
        ->assertDontSee('Recalculate');
});

it('edits production annotations without recalculating frozen totals', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-060',
        'manufacture_date' => '2026-06-15',
        'batch_basis' => 1000,
        'units_produced' => 10,
    ]);

    $this->actingAs($user)
        ->patch(route('production-batches.update', $batch), [
            'production_batch_number' => 'B-2026-060-A',
            'production_notes' => 'Updated QC notes.',
            'ingredient_lot_numbers' => [
                "{$ingredient->id}:saponified_oils:1" => 'OO-LOT-60',
            ],
            'batch_basis' => 9000,
            'units_produced' => 99,
        ])
        ->assertRedirect(route('production-batches.show', $batch));

    $batch->refresh();

    expect($batch->production_batch_number)->toBe('B-2026-060-A')
        ->and($batch->production_notes)->toBe('Updated QC notes.')
        ->and($batch->batch_basis_value)->toBe('1000.000')
        ->and($batch->units_produced)->toBe(10)
        ->and($batch->total_cost)->toBe('11.0000')
        ->and($batch->ingredients->first()->ingredient_lot_number)->toBe('OO-LOT-60');
});

it('prints production notes without helper text and leaves writing space', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-070',
        'manufacture_date' => '2026-06-16',
        'batch_basis' => 1000,
        'units_produced' => 10,
        'production_notes' => 'Cured on rack A.',
    ]);

    $this->actingAs($user)
        ->get(route('production-batches.print', $batch))
        ->assertSuccessful()
        ->assertSee('Production notes')
        ->assertSee('Cured on rack A.')
        ->assertSee('min-h-[8rem]', false)
        ->assertDontSee('Use this space')
        ->assertDontSee('helper');
});
```

- [ ] **Step 2: Run failing view tests**

Run:

```bash
php artisan test --compact --filter="production annotations"
php artisan test --compact --filter="prints production notes"
```

Expected: FAIL because the temporary views are minimal.

- [ ] **Step 3: Implement snapshot show page**

Replace `resources/views/production-batches/show.blade.php` with:

```blade
@extends('layouts.app-shell')

@php
    $formatNumber = static fn (mixed $value, int $decimals = 2): string => rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
    $formatMoney = static fn (mixed $value, string $currency, int $decimals = 2): string => rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.').' '.$currency;
@endphp

@section('title', $productionBatch->recipe_name.' · Production Snapshot · '.config('app.name'))
@section('page_heading', 'Production Snapshot')

@section('content')
    <div class="mx-auto max-w-[90rem] space-y-6">
        <section class="sk-card p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="sk-eyebrow">Production snapshot</p>
                    <h1 class="mt-2 text-2xl font-semibold text-[var(--color-ink-strong)]">{{ $productionBatch->recipe_name }}</h1>
                    <p class="mt-2 text-sm text-[var(--color-ink-soft)]">
                        Version {{ $productionBatch->recipe_version_number }} · {{ $productionBatch->manufacture_date->format('Y-m-d') }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('recipes.saved', $productionBatch->recipe_id) }}" class="rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">Back to formula</a>
                    <a href="{{ route('production-batches.print', $productionBatch) }}" class="rounded-full bg-[var(--color-ink-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">Print</a>
                </div>
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-4">
            <div class="sk-card p-4"><p class="sk-eyebrow">Batch</p><p class="mt-2 font-semibold text-[var(--color-ink-strong)]">{{ $productionBatch->production_batch_number ?: 'No batch number' }}</p></div>
            <div class="sk-card p-4"><p class="sk-eyebrow">{{ $productionBatch->batch_basis_label }}</p><p class="numeric mt-2 font-semibold text-[var(--color-ink-strong)]">{{ $formatNumber($productionBatch->batch_basis_value) }} {{ $productionBatch->batch_basis_unit }}</p></div>
            <div class="sk-card p-4"><p class="sk-eyebrow">Units produced</p><p class="numeric mt-2 font-semibold text-[var(--color-ink-strong)]">{{ $productionBatch->units_produced }}</p></div>
            <div class="sk-card p-4"><p class="sk-eyebrow">Cost per unit</p><p class="numeric mt-2 font-semibold text-[var(--color-ink-strong)]">{{ $formatMoney($productionBatch->cost_per_unit, $productionBatch->currency, 4) }}</p></div>
        </section>

        <section class="sk-card overflow-hidden">
            <div class="border-b border-[var(--color-line)] px-5 py-4">
                <p class="sk-eyebrow">Ingredients</p>
                <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Ingredient rows</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-[var(--color-panel)] text-xs uppercase text-[var(--color-ink-soft)]">
                        <tr>
                            <th class="px-4 py-3">Phase</th>
                            <th class="px-4 py-3">Ingredient</th>
                            <th class="numeric px-4 py-3">Quantity</th>
                            <th class="numeric px-4 py-3">Price/kg</th>
                            <th class="numeric px-4 py-3">Cost</th>
                            <th class="px-4 py-3">Ingredient lot number</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @foreach ($productionBatch->ingredients as $row)
                            <tr>
                                <td class="px-4 py-3">{{ $row->phase_name }}</td>
                                <td class="px-4 py-3 font-medium text-[var(--color-ink-strong)]">{{ $row->ingredient_name }}</td>
                                <td class="numeric px-4 py-3">{{ $formatNumber($row->quantity) }} {{ $row->unit }}</td>
                                <td class="numeric px-4 py-3">{{ $row->price_per_kg === null ? 'Not set' : $formatMoney($row->price_per_kg, $productionBatch->currency, 4) }}</td>
                                <td class="numeric px-4 py-3">{{ $formatMoney($row->line_cost, $productionBatch->currency) }}</td>
                                <td class="px-4 py-3">{{ $row->ingredient_lot_number ?: 'Not recorded' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="sk-card overflow-hidden">
            <div class="border-b border-[var(--color-line)] px-5 py-4">
                <p class="sk-eyebrow">Packaging</p>
                <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Packaging rows</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-[var(--color-panel)] text-xs uppercase text-[var(--color-ink-soft)]">
                        <tr>
                            <th class="px-4 py-3">Item</th>
                            <th class="numeric px-4 py-3">Components/unit</th>
                            <th class="numeric px-4 py-3">Unit cost</th>
                            <th class="numeric px-4 py-3">Batch cost</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-line)]">
                        @foreach ($productionBatch->packagingItems as $row)
                            <tr>
                                <td class="px-4 py-3 font-medium text-[var(--color-ink-strong)]">{{ $row->name }}</td>
                                <td class="numeric px-4 py-3">{{ $formatNumber($row->components_per_unit, 3) }}</td>
                                <td class="numeric px-4 py-3">{{ $formatMoney($row->unit_cost, $productionBatch->currency, 4) }}</td>
                                <td class="numeric px-4 py-3">{{ $formatMoney($row->line_cost, $productionBatch->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <form method="POST" action="{{ route('production-batches.update', $productionBatch) }}" class="sk-card p-5">
            @csrf
            @method('PATCH')
            <p class="sk-eyebrow">Editable annotations</p>
            <h2 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Production notes</h2>

            <label class="mt-4 block">
                <span class="text-sm font-medium text-[var(--color-ink-strong)]">Production batch number</span>
                <input name="production_batch_number" value="{{ old('production_batch_number', $productionBatch->production_batch_number) }}" type="text" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
            </label>

            <div class="mt-4 grid gap-3">
                @foreach ($productionBatch->ingredients as $row)
                    @php($lotKey = implode(':', [$row->ingredient_id, $row->phase_key, $row->position]))
                    <label class="block">
                        <span class="text-sm font-medium text-[var(--color-ink-strong)]">{{ $row->ingredient_name }} lot</span>
                        <input name="ingredient_lot_numbers[{{ $lotKey }}]" value="{{ old('ingredient_lot_numbers.'.$lotKey, $row->ingredient_lot_number) }}" type="text" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]" />
                    </label>
                @endforeach
            </div>

            <label class="mt-4 block">
                <span class="text-sm font-medium text-[var(--color-ink-strong)]">Production notes</span>
                <textarea name="production_notes" rows="8" class="mt-2 w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]">{{ old('production_notes', $productionBatch->production_notes) }}</textarea>
            </label>

            <button type="submit" class="mt-4 rounded-full bg-[var(--color-ink-strong)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[var(--color-accent-strong)]">
                Save notes
            </button>
        </form>
    </div>
@endsection
```

- [ ] **Step 4: Implement snapshot printout**

Replace `resources/views/production-batches/print.blade.php` with:

```blade
@extends('layouts.print')

@php
    $formatNumber = static fn (mixed $value, int $decimals = 2): string => rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
    $formatMoney = static fn (mixed $value, string $currency, int $decimals = 2): string => rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.').' '.$currency;
@endphp

@section('title', $productionBatch->recipe_name.' · Production Print · '.config('app.name'))

@section('content')
    <div class="space-y-4 text-[13px] leading-5 text-slate-950">
        <div class="print-hidden flex flex-wrap justify-between gap-2 border border-slate-300 bg-white p-4">
            <a href="{{ route('production-batches.show', $productionBatch) }}" class="inline-flex rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-800 transition hover:bg-slate-50">Back</a>
            <button type="button" onclick="window.print()" class="inline-flex rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700">Print</button>
        </div>

        <article class="document-sheet border border-slate-300 bg-white p-6 print:border-0 print:p-0">
            <header class="border-b-2 border-slate-950 pb-3">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Production snapshot</p>
                <h1 class="mt-1 text-2xl font-semibold text-slate-950">{{ $productionBatch->recipe_name }}</h1>
                <dl class="mt-3 grid grid-cols-[9rem_minmax(0,1fr)] gap-x-2 gap-y-1 text-xs text-slate-700">
                    <dt class="font-semibold text-slate-500">Version</dt><dd>{{ $productionBatch->recipe_version_number }}</dd>
                    <dt class="font-semibold text-slate-500">Batch</dt><dd>{{ $productionBatch->production_batch_number ?: 'No batch number' }}</dd>
                    <dt class="font-semibold text-slate-500">Date made</dt><dd>{{ $productionBatch->manufacture_date->format('Y-m-d') }}</dd>
                    <dt class="font-semibold text-slate-500">{{ $productionBatch->batch_basis_label }}</dt><dd>{{ $formatNumber($productionBatch->batch_basis_value) }} {{ $productionBatch->batch_basis_unit }}</dd>
                    <dt class="font-semibold text-slate-500">Units produced</dt><dd>{{ $productionBatch->units_produced }}</dd>
                </dl>
            </header>

            <section class="mt-4">
                <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Ingredients</h2>
                <table class="mt-2 w-full border-collapse text-sm">
                    <thead>
                        <tr class="border border-slate-300 bg-slate-100 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">
                            <th class="px-2 py-1.5">Ingredient</th>
                            <th class="px-2 py-1.5">Quantity</th>
                            <th class="px-2 py-1.5">Lot</th>
                            <th class="px-2 py-1.5">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($productionBatch->ingredients as $row)
                            <tr class="border border-slate-300">
                                <td class="px-2 py-1.5 font-medium">{{ $row->ingredient_name }}</td>
                                <td class="numeric px-2 py-1.5">{{ $formatNumber($row->quantity) }} {{ $row->unit }}</td>
                                <td class="px-2 py-1.5">{{ $row->ingredient_lot_number ?: '' }}&nbsp;</td>
                                <td class="numeric px-2 py-1.5">{{ $formatMoney($row->line_cost, $productionBatch->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <section class="mt-4">
                <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Packaging</h2>
                <table class="mt-2 w-full border-collapse text-sm">
                    <thead>
                        <tr class="border border-slate-300 bg-slate-100 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">
                            <th class="px-2 py-1.5">Item</th>
                            <th class="px-2 py-1.5">Components/unit</th>
                            <th class="px-2 py-1.5">Batch cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($productionBatch->packagingItems as $row)
                            <tr class="border border-slate-300">
                                <td class="px-2 py-1.5 font-medium">{{ $row->name }}</td>
                                <td class="numeric px-2 py-1.5">{{ $formatNumber($row->components_per_unit, 3) }}</td>
                                <td class="numeric px-2 py-1.5">{{ $formatMoney($row->line_cost, $productionBatch->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>

            <section class="mt-4">
                <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Cost summary</h2>
                <table class="mt-2 w-full border-collapse text-sm">
                    <tbody>
                        <tr class="border border-slate-300"><th class="w-48 bg-slate-100 px-2 py-1.5 text-left font-semibold">Ingredient cost</th><td class="numeric px-2 py-1.5">{{ $formatMoney($productionBatch->ingredient_total, $productionBatch->currency) }}</td></tr>
                        <tr class="border border-slate-300"><th class="bg-slate-100 px-2 py-1.5 text-left font-semibold">Packaging cost</th><td class="numeric px-2 py-1.5">{{ $formatMoney($productionBatch->packaging_total, $productionBatch->currency) }}</td></tr>
                        <tr class="border border-slate-300"><th class="bg-slate-100 px-2 py-1.5 text-left font-semibold">Total production cost</th><td class="numeric px-2 py-1.5">{{ $formatMoney($productionBatch->total_cost, $productionBatch->currency) }}</td></tr>
                        <tr class="border border-slate-300"><th class="bg-slate-100 px-2 py-1.5 text-left font-semibold">Cost per unit</th><td class="numeric px-2 py-1.5">{{ $formatMoney($productionBatch->cost_per_unit, $productionBatch->currency, 4) }}</td></tr>
                    </tbody>
                </table>
            </section>

            <section class="mt-4 break-inside-avoid">
                <h2 class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Production notes</h2>
                <div class="mt-2 min-h-[8rem] border border-slate-300 px-3 py-2 text-sm leading-6">
                    {!! nl2br(e($productionBatch->production_notes ?? '')) !!}
                </div>
            </section>
        </article>
    </div>
@endsection
```

- [ ] **Step 5: Run view tests**

Run:

```bash
php artisan test --compact --filter="production annotations"
php artisan test --compact --filter="prints production notes"
```

Expected: PASS.

- [ ] **Step 6: Format, build, and commit**

Run:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/ProductionSnapshotsTest.php
npm run build
git add resources/views/production-batches/show.blade.php resources/views/production-batches/print.blade.php tests/Feature/ProductionSnapshotsTest.php public
git commit -m "feat: show production snapshots"
```

Expected: tests pass, Vite build succeeds, and commit succeeds.

---

### Task 7: Live Price Propagation Without Touching Snapshots

**Files:**
- Create: `app/Services/LiveCostingPricePropagationService.php`
- Modify: `app/Services/UserIngredientPriceMemory.php`
- Modify: `app/Services/UserPackagingItemAuthoringService.php`
- Modify: `app/Services/RecipeVersionCostingSynchronizer.php`
- Modify: `tests/Feature/RecipeVersionCostingTest.php`
- Modify: `tests/Feature/ProductionSnapshotsTest.php`

- [ ] **Step 1: Write failing ingredient price propagation test**

Append this test to `tests/Feature/RecipeVersionCostingTest.php`:

```php
use App\Services\UserIngredientPriceMemory;

it('propagates ingredient price memory changes to linked live costing rows', function (): void {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSharedCarrierOilIngredient();
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft($user, $soapFamily, soapDraftPayload($ingredient));
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $service->saveCosting($user, $recipe, [
        'oil_weight_for_costing' => 1000,
        'oil_unit_for_costing' => 'g',
        'units_produced' => 8,
        'currency' => 'EUR',
        'items' => [
            [
                'ingredient_id' => $ingredient->id,
                'phase_key' => 'saponified_oils',
                'position' => 1,
                'price_per_kg' => 8,
            ],
        ],
        'packaging_items' => [],
    ]);

    app(UserIngredientPriceMemory::class)->remember($user, $ingredient->id, 9.5, 'EUR');

    $costing = RecipeVersionCosting::query()
        ->where('recipe_version_id', $draftVersion->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($costing->items()->firstOrFail()->price_per_kg)->toBe('9.5000');
});
```

- [ ] **Step 2: Write failing packaging price propagation test**

Append this test to `tests/Feature/RecipeVersionCostingTest.php`:

```php
use App\Services\UserPackagingItemAuthoringService;

it('propagates packaging catalog unit cost changes to linked live costing rows', function (): void {
    $user = User::factory()->create();
    $soapFamily = ProductFamily::factory()->create([
        'slug' => 'soap',
        'name' => 'Soap',
    ]);
    $ingredient = makeSharedCarrierOilIngredient();
    $packagingItem = UserPackagingItem::query()->create([
        'user_id' => $user->id,
        'name' => 'Gift Box',
        'unit_cost' => 0.4,
        'currency' => 'EUR',
    ]);
    $service = app(RecipeWorkbenchService::class);

    $draftVersion = $service->saveDraft($user, $soapFamily, soapDraftPayload($ingredient) + [
        'packaging_items' => [
            [
                'user_packaging_item_id' => $packagingItem->id,
                'name' => 'Gift Box',
                'components_per_unit' => 1,
            ],
        ],
    ]);
    $recipe = Recipe::withoutGlobalScopes()->findOrFail($draftVersion->recipe_id);
    $service->costingPayload($recipe, $user);

    app(UserPackagingItemAuthoringService::class)->updateUnitCost($packagingItem, $user, 0.73);

    $costing = RecipeVersionCosting::query()
        ->where('recipe_version_id', $draftVersion->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect($costing->packagingItems()->firstOrFail()->unit_cost)->toBe('0.7300');
});
```

- [ ] **Step 3: Run failing propagation tests**

Run:

```bash
php artisan test --compact --filter="propagates ingredient price memory"
php artisan test --compact --filter="propagates packaging catalog"
```

Expected: FAIL because current live costing rows are not propagated from catalog/current price edits.

- [ ] **Step 4: Implement propagation service**

Create `app/Services/LiveCostingPricePropagationService.php`:

```php
<?php

namespace App\Services;

use App\Models\RecipeVersionCostingItem;
use App\Models\RecipeVersionCostingPackagingItem;
use App\Models\User;

class LiveCostingPricePropagationService
{
    public function ingredientPriceChanged(User $user, int $ingredientId, float $pricePerKg): void
    {
        RecipeVersionCostingItem::query()
            ->where('ingredient_id', $ingredientId)
            ->whereHas('costing', fn ($query) => $query->where('user_id', $user->id))
            ->update([
                'price_per_kg' => round($pricePerKg, 4),
                'updated_at' => now(),
            ]);
    }

    public function packagingUnitCostChanged(User $user, int $packagingItemId, float $unitCost): void
    {
        RecipeVersionCostingPackagingItem::query()
            ->where('user_packaging_item_id', $packagingItemId)
            ->whereHas('costing', fn ($query) => $query->where('user_id', $user->id))
            ->update([
                'unit_cost' => round($unitCost, 4),
                'updated_at' => now(),
            ]);
    }
}
```

- [ ] **Step 5: Inject propagation into ingredient price memory**

Modify `app/Services/UserIngredientPriceMemory.php`:

```php
public function __construct(
    private readonly LiveCostingPricePropagationService $pricePropagationService,
) {}
```

Then replace the return block in `remember()` with:

```php
$price = UserIngredientPrice::query()->updateOrCreate(
    [
        'user_id' => $user->id,
        'ingredient_id' => $ingredientId,
    ],
    [
        'price_per_kg' => round($pricePerKg, 4),
        'currency' => $currency,
        'last_used_at' => now(),
    ],
);

$this->pricePropagationService->ingredientPriceChanged($user, $ingredientId, (float) $price->price_per_kg);

return $price;
```

- [ ] **Step 6: Inject propagation into packaging authoring service**

Modify `app/Services/UserPackagingItemAuthoringService.php`:

```php
public function __construct(
    private readonly LiveCostingPricePropagationService $pricePropagationService,
) {}
```

After `$packagingItem->save();` in `updateUnitCost()`, add:

```php
$this->pricePropagationService->packagingUnitCostChanged($user, $packagingItem->id, $unitCost);
```

In `persist()`, after `$packagingItem->save();`, add:

```php
if ($packagingItem->exists && $packagingItem->wasChanged('unit_cost')) {
    $this->pricePropagationService->packagingUnitCostChanged(
        $packagingItem->user,
        $packagingItem->id,
        (float) $packagingItem->unit_cost,
    );
}
```

- [ ] **Step 7: Inject propagation into costing catalog saves**

Modify the constructor in `app/Services/RecipeVersionCostingSynchronizer.php`:

```php
public function __construct(
    private readonly UserIngredientPriceMemory $userIngredientPriceMemory,
    private readonly LiveCostingPricePropagationService $pricePropagationService,
) {}
```

After `$packagingItem->save();` in `savePackagingItem()`, add:

```php
$this->pricePropagationService->packagingUnitCostChanged($user, $packagingItem->id, (float) $packagingItem->unit_cost);
```

After the linked packaging item save call in `replacePackagingItems()`, add:

```php
$this->pricePropagationService->packagingUnitCostChanged(
    $linkedPackagingItem->user,
    $linkedPackagingItem->id,
    $unitCost,
);
```

- [ ] **Step 8: Add snapshot exclusion test**

Add this import near the top of `tests/Feature/ProductionSnapshotsTest.php`:

```php
use App\Services\UserIngredientPriceMemory;
```

Append this test to `tests/Feature/ProductionSnapshotsTest.php`:

```php
it('does not change frozen production snapshot prices when live prices change', function (): void {
    [$user, $recipe, $version, $ingredient] = productionSnapshotSoapRecipe();
    productionSnapshotAttachCosting($user, $version, $ingredient, ingredientPrice: 8.5, packagingPrice: 0.25);
    $batch = app(ProductionSnapshotService::class)->record($recipe, $version, $user, [
        'production_batch_number' => 'B-2026-080',
        'manufacture_date' => '2026-06-17',
        'batch_basis' => 1000,
        'units_produced' => 10,
    ]);

    app(UserIngredientPriceMemory::class)->remember($user, $ingredient->id, 99, 'EUR');

    expect($batch->fresh()->ingredients->first()->price_per_kg)->toBe('8.5000')
        ->and($batch->fresh()->total_cost)->toBe('11.0000');
});
```

- [ ] **Step 9: Run propagation tests**

Run:

```bash
php artisan test --compact --filter="propagates ingredient price memory"
php artisan test --compact --filter="propagates packaging catalog"
php artisan test --compact --filter="does not change frozen production snapshot prices"
```

Expected: PASS.

- [ ] **Step 10: Format and commit**

Run:

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/RecipeVersionCostingTest.php
php artisan test --compact tests/Feature/ProductionSnapshotsTest.php
git add app/Services/LiveCostingPricePropagationService.php app/Services/UserIngredientPriceMemory.php app/Services/UserPackagingItemAuthoringService.php app/Services/RecipeVersionCostingSynchronizer.php tests/Feature/RecipeVersionCostingTest.php tests/Feature/ProductionSnapshotsTest.php
git commit -m "feat: propagate live costing prices"
```

Expected: tests pass and commit succeeds.

---

### Task 8: Final Verification

**Files:**
- Verify: `app/Services/ProductionSnapshotService.php`
- Verify: `resources/views/recipes/version.blade.php`
- Verify: `resources/views/production-batches/show.blade.php`
- Verify: `resources/views/production-batches/print.blade.php`

- [ ] **Step 1: Run focused production tests**

Run:

```bash
php artisan test --compact tests/Feature/ProductionSnapshotsTest.php
```

Expected: PASS.

- [ ] **Step 2: Run affected recipe and costing tests**

Run:

```bash
php artisan test --compact tests/Feature/RecipeVersionPagesTest.php
php artisan test --compact tests/Feature/RecipeVersionPackagingPlanTest.php
php artisan test --compact tests/Feature/RecipeVersionCostingTest.php
```

Expected: PASS.

- [ ] **Step 3: Run formatter and frontend build**

Run:

```bash
vendor/bin/pint --dirty --format agent
npm run build
```

Expected: Pint completes and Vite build succeeds.

- [ ] **Step 4: Refresh graphify**

Run:

```bash
graphify update .
```

Expected: graphify completes and updates `graphify-out/`.

- [ ] **Step 5: Inspect final diff**

Run:

```bash
git status --short
git diff --stat
```

Expected: only files touched by the production snapshots implementation and graphify output are changed.

- [ ] **Step 6: Commit final verification output if graphify changed**

Run:

```bash
git add graphify-out
git commit -m "chore: refresh graph after production snapshots"
```

Expected: commit succeeds if graphify produced changes. If graphify did not change any tracked files, skip this commit.
