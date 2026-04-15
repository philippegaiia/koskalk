# Price Management and Ingredient Duplication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users manage their saved ingredient prices outside of recipe costing, duplicate platform ingredients into their own catalog, and remove dead columns from the ingredients table.

**Architecture:** Price management adds a second section to the existing `IngredientsIndex` Livewire page backed by the existing `user_ingredient_prices` table. Duplication adds a `duplicate()` method to `UserIngredientAuthoringService` that deep-copies a platform ingredient into a user-owned record, triggered from a search modal on the same Ingredients page. Dead columns are dropped in a migration with all referencing code cleaned up.

**Tech Stack:** Laravel 13, Livewire, Filament TableComponent (existing), Alpine.js, Blade, Tailwind CSS 4, PostgreSQL, Pest PHP.

---

## File Structure

### New Files
- `database/migrations/2026_04_12_000001_drop_dead_ingredient_columns.php`
  Responsibility: drop `price_eur` and `display_name_en` from `ingredients`.
- `tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php`
  Responsibility: prove the duplication service deep-copies all data correctly.
- `tests/Feature/IngredientsIndexPriceTest.php`
  Responsibility: prove the priced-ingredients section shows and edits user prices.
- `tests/Feature/IngredientsIndexDuplicationTest.php`
  Responsibility: prove the duplication modal creates a user-owned copy from a platform ingredient.
- `resources/views/livewire/dashboard/partials/priced-ingredients-section.blade.php`
  Responsibility: render the priced-ingredients table with editable price inputs.
- `resources/views/livewire/dashboard/partials/duplicate-ingredient-modal.blade.php`
  Responsibility: render the search/select modal for duplicating platform ingredients.

### Modified Files
- `app/Models/Ingredient.php`
  Responsibility: remove `price_eur` and `display_name_en` from fillable and casts.
- `app/Services/IngredientDataEntryService.php`
  Responsibility: remove reads/writes of `price_eur` and `display_name_en`.
- `app/Services/UserIngredientAuthoringService.php`
  Responsibility: add `duplicate(Ingredient $source, User $user)` method.
- `app/Livewire/Dashboard/IngredientsIndex.php`
  Responsibility: add priced-ingredients query, price editing action, duplication modal state, duplication search action, and duplication execute action.
- `resources/views/livewire/dashboard/ingredients-index.blade.php`
  Responsibility: include the priced-ingredients section and duplication modal.
- `database/factories/IngredientFactory.php`
  Responsibility: remove `price_eur` and `display_name_en` from factory definition.
- `database/seeders/IngredientCatalogSeeder.php`
  Responsibility: remove `price_eur` and `display_name_en` from seeder output.
- `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`
  Responsibility: remove form inputs for `price_eur` and `display_name_en`.
- `app/Filament/Exports/IngredientExporter.php`
  Responsibility: remove export columns for `price_eur` and `display_name_en`.
- `tests/Feature/IngredientDataEntryServiceTest.php`
  Responsibility: remove `price_eur` assertion from test data.
- `tests/Feature/CatalogSeederTest.php`
  Responsibility: remove `price_eur` and `display_name_en` assertions.
- `app/Services/InciGenerationService.php`
  Responsibility: add `source_is_user_owned` boolean array to ingredient and declaration rows.
- `resources/views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php`
  Responsibility: render colored dot next to user-owned source ingredients.
- `resources/js/recipe-workbench/sections/presentation-section.js`
  Responsibility: pass through `source_is_user_owned` in computed rows.

### Deliberate Non-Changes
- No changes to the costing tab, packaging flow, or recipe workbench interaction.
- No changes to `RecipeVersionCostingSynchronizer` — it already reads/writes `user_ingredient_prices`.
- No new database tables. Duplication reuses the existing `ingredients` table.

---

### Task 1: Remove Dead Columns

**Files:**
- Create: `database/migrations/2026_04_12_000001_drop_dead_ingredient_columns.php`
- Modify: `app/Models/Ingredient.php`
- Modify: `app/Services/IngredientDataEntryService.php`
- Modify: `database/factories/IngredientFactory.php`
- Modify: `database/seeders/IngredientCatalogSeeder.php`
- Modify: `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`
- Modify: `app/Filament/Exports/IngredientExporter.php`
- Modify: `tests/Feature/IngredientDataEntryServiceTest.php`
- Modify: `tests/Feature/CatalogSeederTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropColumn('price_eur');
            $table->dropColumn('display_name_en');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->decimal('price_eur', 10, 2)->nullable()->after('unit');
            $table->string('display_name_en', 255)->nullable()->after('display_name');
        });
    }
};
```

- [ ] **Step 2: Update the Ingredient model**

In `app/Models/Ingredient.php`, remove `'display_name_en'` and `'price_eur'` from the `#[Fillable]` attribute array (lines 26 and 36). Remove the `'price_eur' => 'decimal:2'` cast from `casts()` (line 258).

- [ ] **Step 3: Update IngredientDataEntryService**

In `app/Services/IngredientDataEntryService.php`:

Remove `'display_name_en'` from `formData()` return (line 25):
```php
// Remove this line:
'display_name_en' => $ingredient->display_name_en,
```

Remove `'price_eur'` from `formData()` return (line 35):
```php
// Remove this line:
'price_eur' => $ingredient->price_eur === null ? null : (float) $ingredient->price_eur,
```

Remove `'display_name_en'` from `syncCurrentData()` fill (line 86):
```php
// Remove this line:
'display_name_en' => $currentVersionState['display_name_en'] ?? null,
```

Remove `'price_eur'` from `syncCurrentData()` fill (line 100):
```php
// Remove this line:
'price_eur' => $currentVersionState['price_eur'] ?? null,
```

- [ ] **Step 4: Update the factory**

In `database/factories/IngredientFactory.php`, remove these two lines from the `definition()` return:
```php
'display_name_en' => null,  // line 28
'price_eur' => null,         // line 38
```

- [ ] **Step 5: Update the seeder**

In `database/seeders/IngredientCatalogSeeder.php`, remove `'display_name_en'` and `'price_eur'` from the row-building logic (lines 51 and 58).

- [ ] **Step 6: Update the Filament admin form**

In `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`, remove these two form fields:
- `TextInput::make('current_version.display_name_en')` block (lines 88-90)
- `TextInput::make('current_version.price_eur')` block (lines 111-114)

- [ ] **Step 7: Update the Filament exporter**

In `app/Filament/Exports/IngredientExporter.php`, remove these two export columns:
- `ExportColumn::make('display_name_en')` (line 22)
- `ExportColumn::make('price_eur')` (line 36)

- [ ] **Step 8: Update tests referencing dead columns**

In `tests/Feature/IngredientDataEntryServiceTest.php`, remove the `'price_eur' => 12.5` line from the sync test data (line 39).

In `tests/Feature/CatalogSeederTest.php`:
- Remove `->and($oliveOil->display_name_en)->toBe('Olive oil virgin')` from the seed assertion (line 64).
- Remove `->and(Ingredient::query()->firstOrFail()->price_eur)->toBe('3.10')` from the price import test (line 99).

- [ ] **Step 9: Run the migration and tests**

Run: `php artisan migrate`
Run: `php artisan test --compact`
Expected: all tests pass.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "refactor: drop price_eur and display_name_en from ingredients"
```

---

### Task 2: Add Duplication Service Method

**Files:**
- Modify: `app/Services/UserIngredientAuthoringService.php`
- Create: `tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php`

- [ ] **Step 1: Write the failing duplication test**

```php
<?php

use App\IngredientCategory;
use App\Models\Allergen;
use App\Models\FattyAcid;
use App\Models\IfraProductCategory;
use App\Models\Ingredient;
use App\Models\IngredientFunction;
use App\Models\User;
use App\OwnerType;
use App\Services\UserIngredientAuthoringService;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('duplicates a platform ingredient into a user-owned copy with all data except images', function () {
    $user = User::factory()->create();
    $function = IngredientFunction::factory()->create(['is_active' => true]);
    $allergen = Allergen::factory()->create();
    $ifraCategory = IfraProductCategory::factory()->create(['is_active' => true]);
    $oleic = FattyAcid::factory()->create(['key' => 'oleic', 'name' => 'Oleic']);

    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::EssentialOil,
        'display_name' => 'Lavender 40/42',
        'inci_name' => 'LAVANDULA ANGUSTIFOLIA OIL',
        'supplier_name' => 'Supplier A',
        'supplier_reference' => 'REF-123',
        'cas_number' => '8000-28-0',
        'ec_number' => '289-995-2',
        'is_organic' => true,
        'owner_type' => null,
        'owner_id' => null,
        'visibility' => Visibility::Public,
        'is_potentially_saponifiable' => false,
        'featured_image_path' => 'ingredients/featured-images/lavender.webp',
        'icon_image_path' => 'ingredients/icons/lavender.webp',
        'info_markdown' => 'A popular essential oil.',
        'is_active' => true,
    ]);

    $source->functions()->sync([$function->id]);
    $source->allergenEntries()->create([
        'allergen_id' => $allergen->id,
        'concentration_percent' => 2.5,
        'source_notes' => 'Supplier spec',
    ]);
    $source->ifraCertificates()->create([
        'certificate_name' => 'Lavender IFRA',
        'ifra_amendment' => '50th',
        'peroxide_value' => 12.0,
        'source_notes' => 'Certificate data',
        'is_current' => true,
    ])->limits()->create([
        'ifra_product_category_id' => $ifraCategory->id,
        'max_percentage' => 5.0,
        'restriction_note' => 'Standard limit',
    ]);

    $service = app(UserIngredientAuthoringService::class);
    $copy = $service->duplicate($source, $user);

    expect($copy->owner_type)->toBe(OwnerType::User);
    expect($copy->owner_id)->toBe($user->id);
    expect($copy->visibility)->toBe(Visibility::Private);
    expect($copy->display_name)->toBe('Lavender 40/42');
    expect($copy->inci_name)->toBe('LAVANDULA ANGUSTIFOLIA OIL');
    expect($copy->supplier_name)->toBe('Supplier A');
    expect($copy->cas_number)->toBe('8000-28-0');
    expect($copy->is_organic)->toBeTrue();
    expect($copy->featured_image_path)->toBeNull();
    expect($copy->icon_image_path)->toBeNull();
    expect($copy->info_markdown)->toBe('A popular essential oil.');
    expect($copy->is_active)->toBeTrue();
    expect($copy->source_file)->toBe('user');
    expect($copy->id)->not->toBe($source->id);

    $copy->load(['functions', 'allergenEntries', 'ifraCertificates.limits']);
    expect($copy->functions)->toHaveCount(1);
    expect($copy->functions->first()->id)->toBe($function->id);
    expect($copy->allergenEntries)->toHaveCount(1);
    expect($copy->allergenEntries->first()->allergen_id)->toBe($allergen->id);
    expect((float) $copy->allergenEntries->first()->concentration_percent)->toBe(2.5);
    expect($copy->ifraCertificates)->toHaveCount(1);
    expect($copy->ifraCertificates->first()->limits)->toHaveCount(1);

    // Original is unchanged
    expect($source->fresh()->owner_type)->toBeNull();
    expect(Ingredient::query()->count())->toBe(2);
});

it('duplicates a carrier oil with SAP profile and fatty acids', function () {
    $user = User::factory()->create();
    $oleic = FattyAcid::factory()->create(['key' => 'oleic', 'name' => 'Oleic']);
    $palmitic = FattyAcid::factory()->create(['key' => 'palmitic', 'name' => 'Palmitic']);

    $source = Ingredient::factory()->create([
        'category' => IngredientCategory::CarrierOil,
        'display_name' => 'Olive Oil',
        'owner_type' => null,
        'owner_id' => null,
        'is_potentially_saponifiable' => true,
        'is_active' => true,
    ]);

    $source->sapProfile()->create([
        'koh_sap_value' => 0.188,
        'iodine_value' => 86.4,
        'ins_value' => 102.8,
        'source_notes' => 'Trusted average',
    ]);
    $source->fattyAcidEntries()->createMany([
        ['fatty_acid_id' => $oleic->id, 'percentage' => 71.0, 'source_notes' => 'Main'],
        ['fatty_acid_id' => $palmitic->id, 'percentage' => 13.0, 'source_notes' => null],
    ]);

    $service = app(UserIngredientAuthoringService::class);
    $copy = $service->duplicate($source, $user);

    expect($copy->is_potentially_saponifiable)->toBeTrue();
    expect($copy->sapProfile)->not->toBeNull();
    expect((float) $copy->sapProfile->koh_sap_value)->toBe(0.188);
    expect((float) $copy->sapProfile->iodine_value)->toBe(86.4);
    expect($copy->fattyAcidEntries)->toHaveCount(2);

    // SAP profile is independent
    $copy->sapProfile->update(['koh_sap_value' => 0.195]);
    expect((float) $source->fresh()->sapProfile->koh_sap_value)->toBe(0.188);
});

it('duplicates a composite ingredient with components', function () {
    $user = User::factory()->create();

    $component = Ingredient::factory()->create([
        'display_name' => 'Base oil component',
        'category' => IngredientCategory::CarrierOil,
        'is_active' => true,
    ]);

    $source = Ingredient::factory()->create([
        'display_name' => 'Soap base blend',
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    $source->components()->create([
        'component_ingredient_id' => $component->id,
        'percentage_in_parent' => 100.0,
        'sort_order' => 1,
        'source_notes' => 'Full blend',
    ]);

    $service = app(UserIngredientAuthoringService::class);
    $copy = $service->duplicate($source, $user);

    expect($copy->components)->toHaveCount(1);
    expect($copy->components->first()->component_ingredient_id)->toBe($component->id);
    expect((float) $copy->components->first()->percentage_in_parent)->toBe(100.0);
});

it('refuses to duplicate a user-owned ingredient', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $source = Ingredient::factory()->create([
        'owner_type' => OwnerType::User,
        'owner_id' => $owner->id,
        'visibility' => Visibility::Private,
    ]);

    $service = app(UserIngredientAuthoringService::class);

    expect(fn () => $service->duplicate($source, $otherUser))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php`
Expected: FAIL — `Method App\Services\UserIngredientAuthoringService::duplicate does not exist`.

- [ ] **Step 3: Implement the duplicate method**

Add to `app/Services/UserIngredientAuthoringService.php`:

```php
public function duplicate(Ingredient $source, User $user): Ingredient
{
    if ($source->owner_type !== null) {
        throw ValidationException::withMessages([
            'ingredient' => 'Only platform ingredients can be duplicated.',
        ]);
    }

    $copy = $source->replicate([
        'featured_image_path',
        'icon_image_path',
    ]);

    $copy->source_file = 'user';
    $copy->source_key = $this->ingredientDataEntryService->generateSourceKey('USR', 'user');
    $copy->source_code_prefix = 'USR';
    $copy->owner_type = OwnerType::User;
    $copy->owner_id = $user->id;
    $copy->workspace_id = null;
    $copy->visibility = Visibility::Private;
    $copy->requires_admin_review = false;
    $copy->featured_image_path = null;
    $copy->icon_image_path = null;
    $copy->save();

    $this->deepCopyRelations($source, $copy);

    return $copy->fresh([
        'sapProfile',
        'fattyAcidEntries.fattyAcid',
        'components.componentIngredient',
        'allergenEntries.allergen',
        'functions',
        'ifraCertificates.limits.ifraProductCategory',
    ]);
}

private function deepCopyRelations(Ingredient $source, Ingredient $copy): void
{
    // SAP profile
    if ($source->sapProfile) {
        $source->sapProfile->replicate()->fill([
            'ingredient_id' => $copy->id,
        ])->save();
    }

    // Fatty acid entries
    $source->fattyAcidEntries->each(function ($entry) use ($copy): void {
        $entry->replicate()->fill(['ingredient_id' => $copy->id])->save();
    });

    // Components
    $source->components->each(function ($component) use ($copy): void {
        $component->replicate()->fill(['ingredient_id' => $copy->id])->save();
    });

    // Allergen entries
    $source->allergenEntries->each(function ($entry) use ($copy): void {
        $entry->replicate()->fill(['ingredient_id' => $copy->id])->save();
    });

    // Functions
    $copy->functions()->sync($source->functions->pluck('id'));

    // IFRA certificates + limits
    $source->ifraCertificates->each(function ($certificate) use ($copy): void {
        $newCertificate = $certificate->replicate()->fill(['ingredient_id' => $copy->id]);
        $newCertificate->save();

        $certificate->limits->each(function ($limit) use ($newCertificate): void {
            $limit->replicate()->fill(['ingredient_ifra_certificate_id' => $newCertificate->id])->save();
        });
    });
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php`
Expected: PASS — all four tests green.

- [ ] **Step 5: Commit**

```bash
git add app/Services/UserIngredientAuthoringService.php tests/Feature/UserIngredientAuthoringServiceDuplicationTest.php
git commit -m "feat: add ingredient duplication service method"
```

---

### Task 3: Add Priced Ingredients Section

**Files:**
- Modify: `app/Livewire/Dashboard/IngredientsIndex.php`
- Create: `resources/views/livewire/dashboard/partials/priced-ingredients-section.blade.php`
- Modify: `resources/views/livewire/dashboard/ingredients-index.blade.php`
- Create: `tests/Feature/IngredientsIndexPriceTest.php`

- [ ] **Step 1: Write the failing price test**

```php
<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\User;
use App\Models\UserIngredientPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('shows priced platform ingredients in a separate section', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $olive = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    $coconut = Ingredient::factory()->create([
        'display_name' => 'Coconut Oil',
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    UserIngredientPrice::query()->create([
        'user_id' => $user->id,
        'ingredient_id' => $olive->id,
        'price_per_kg' => 5.2500,
        'currency' => 'EUR',
        'last_used_at' => now(),
    ]);

    UserIngredientPrice::query()->create([
        'user_id' => $otherUser->id,
        'ingredient_id' => $coconut->id,
        'price_per_kg' => 3.0000,
        'currency' => 'EUR',
        'last_used_at' => now(),
    ]);

    actingAs($user);

    $this->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('Olive Oil')
        ->assertSee('5.25')
        ->assertDontSee('Coconut Oil')
        ->assertSee('Priced ingredients');
});

it('does not show the priced section when user has no prices', function () {
    $user = User::factory()->create();

    actingAs($user);

    $this->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertDontSee('Priced ingredients');
});

it('updates a user ingredient price from the priced section', function () {
    $user = User::factory()->create();

    $olive = Ingredient::factory()->create([
        'display_name' => 'Olive Oil',
        'category' => IngredientCategory::CarrierOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    UserIngredientPrice::query()->create([
        'user_id' => $user->id,
        'ingredient_id' => $olive->id,
        'price_per_kg' => 5.2500,
        'currency' => 'EUR',
        'last_used_at' => now()->subDay(),
    ]);

    actingAs($user);

    $response = $this->postJson(route('ingredients.update-price'), [
        'ingredient_id' => $olive->id,
        'price_per_kg' => '6.5000',
    ]);

    $response->assertSuccessful();

    $price = UserIngredientPrice::query()
        ->where('user_id', $user->id)
        ->where('ingredient_id', $olive->id)
        ->first();

    expect((float) $price->price_per_kg)->toBe(6.5);
    expect($price->last_used_at->isAfter(now()->subMinute()))->toBeTrue();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/IngredientsIndexPriceTest.php`
Expected: FAIL — route `ingredients.update-price` does not exist, and the Blade view does not contain "Priced ingredients".

- [ ] **Step 3: Add the route for price updates**

In `routes/web.php`, inside the `ingredients.` route group, add:

```php
Route::post('/update-price', 'updatePrice')->name('update-price');
```

- [ ] **Step 4: Add the controller method**

In `app/Http/Controllers/IngredientController.php`, add the `updatePrice` method:

```php
public function updatePrice(Request $request)
{
    $user = $request->user();

    if (! $user instanceof User) {
        return response()->json(['ok' => false], 403);
    }

    $validated = $request->validate([
        'ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
        'price_per_kg' => ['required', 'numeric', 'min:0'],
    ]);

    UserIngredientPrice::query()->updateOrCreate(
        [
            'user_id' => $user->id,
            'ingredient_id' => $validated['ingredient_id'],
        ],
        [
            'price_per_kg' => round((float) $validated['price_per_kg'], 4),
            'currency' => 'EUR',
            'last_used_at' => now(),
        ],
    );

    return response()->json(['ok' => true]);
}
```

Add the necessary imports at the top of the controller:
```php
use App\Models\User;
use App\Models\UserIngredientPrice;
use Illuminate\Http\Request;
```

- [ ] **Step 5: Add priced-ingredients query and pass to the view**

In `app/Livewire/Dashboard/IngredientsIndex.php`, add a property and update `render()`:

Add after the `$currentUserId` property:
```php
public function boot(): void
{
    //
}
```

Update `render()` to include priced ingredients:
```php
public function render(): View
{
    $currentUser = $this->currentUser();
    $pricedIngredients = collect();

    if ($currentUser instanceof User) {
        $pricedIngredients = UserIngredientPrice::query()
            ->where('user_id', $currentUser->id)
            ->with('ingredient')
            ->orderByDesc('last_used_at')
            ->get()
            ->filter(fn (UserIngredientPrice $price) => $price->ingredient !== null);
    }

    return view('livewire.dashboard.ingredients-index', [
        'currentUser' => $currentUser,
        'pricedIngredients' => $pricedIngredients,
    ]);
}
```

Add import: `use App\Models\UserIngredientPrice;`

- [ ] **Step 6: Create the priced-ingredients Blade partial**

Create `resources/views/livewire/dashboard/partials/priced-ingredients-section.blade.php`:

```blade
@section('priced-ingredients')
@if($pricedIngredients->isNotEmpty())
<section class="overflow-hidden rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)]">
    <div class="border-b border-[var(--color-line)] px-5 py-4">
        <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Priced ingredients</p>
        <p class="mt-1 text-sm text-[var(--color-ink-soft)]">Platform ingredients you have priced in recipe costing. Edit the price here and it will prefilled next time.</p>
    </div>

    <div class="divide-y divide-[var(--color-line)]">
        @foreach($pricedIngredients as $priced)
            @php $ingredient = $priced->ingredient; @endphp
            <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                 x-data="{ price: '{{ number_format((float) $priced->price_per_kg, 4, '.', '') }}' }">
                <div class="min-w-0">
                    <p class="font-medium text-[var(--color-ink-strong)]">{{ $ingredient->display_name }}</p>
                    <p class="mt-0.5 text-xs text-[var(--color-ink-soft)]">
                        @if($ingredient->inci_name) {{ $ingredient->inci_name }} &middot; @endif
                        {{ $ingredient->category?->getLabel() }}
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-xs text-[var(--color-ink-soft)]">EUR/kg</span>
                    <input
                        x-model="price"
                        @blur="
                            fetch('{{ route('ingredients.update-price') }}', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                body: JSON.stringify({ ingredient_id: {{ $ingredient->id }}, price_per_kg: price })
                            })
                        "
                        type="text"
                        inputmode="decimal"
                        class="numeric w-24 rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-3 py-2 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
                    />
                </div>
            </div>
        @endforeach
    </div>
</section>
@endif
@endsection
```

- [ ] **Step 7: Include the partial in the ingredients index view**

In `resources/views/livewire/dashboard/ingredients-index.blade.php`, add after the Filament table section (after line 26, before the `@endif`):

```blade
@include('livewire.dashboard.partials.priced-ingredients-section')
```

- [ ] **Step 8: Run the price test**

Run: `php artisan test --compact tests/Feature/IngredientsIndexPriceTest.php`
Expected: PASS — all three tests green.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/Dashboard/IngredientsIndex.php app/Http/Controllers/IngredientController.php routes/web.php resources/views/livewire/dashboard/partials/priced-ingredients-section.blade.php resources/views/livewire/dashboard/ingredients-index.blade.php tests/Feature/IngredientsIndexPriceTest.php
git commit -m "feat: add priced ingredients section to ingredients page"
```

---

### Task 4: Add Duplication UI

**Files:**
- Modify: `app/Livewire/Dashboard/IngredientsIndex.php`
- Create: `resources/views/livewire/dashboard/partials/duplicate-ingredient-modal.blade.php`
- Modify: `resources/views/livewire/dashboard/ingredients-index.blade.php`
- Create: `tests/Feature/IngredientsIndexDuplicationTest.php`

- [ ] **Step 1: Write the failing duplication UI test**

```php
<?php

use App\IngredientCategory;
use App\Models\Ingredient;
use App\Models\User;
use App\OwnerType;
use App\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use function Pest\Laravel\actingAs;
use function Pest\Livewire\livewire;

uses(RefreshDatabase::class);

it('shows a duplicate action in the ingredients page header', function () {
    $user = User::factory()->create();

    actingAs($user);

    $this->get(route('ingredients.index'))
        ->assertSuccessful()
        ->assertSee('Duplicate platform ingredient');
});

it('searches platform ingredients for duplication', function () {
    $user = User::factory()->create();

    Ingredient::factory()->create([
        'display_name' => 'Lavender 40/42',
        'category' => IngredientCategory::EssentialOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    Ingredient::factory()->create([
        'display_name' => 'Peppermint Oil',
        'category' => IngredientCategory::EssentialOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    actingAs($user);

    $response = $this->getJson(route('ingredients.search-platform') . '?q=Lavender');

    $response->assertSuccessful();
    $results = $response->json();
    expect($results)->toHaveCount(1);
    expect($results[0]['name'])->toBe('Lavender 40/42');
});

it('creates a user-owned copy when duplicating a platform ingredient', function () {
    $user = User::factory()->create();

    $source = Ingredient::factory()->create([
        'display_name' => 'Rosemary Oil',
        'inci_name' => 'ROSMARINUS OFFICINALIS OIL',
        'category' => IngredientCategory::EssentialOil,
        'owner_type' => null,
        'owner_id' => null,
        'is_active' => true,
    ]);

    actingAs($user);

    $response = $this->postJson(route('ingredients.duplicate'), [
        'ingredient_id' => $source->id,
    ]);

    $response->assertSuccessful();
    expect($response->json('ok'))->toBeTrue();

    $copy = Ingredient::query()
        ->where('owner_type', OwnerType::User)
        ->where('owner_id', $user->id)
        ->first();

    expect($copy)->not->toBeNull();
    expect($copy->display_name)->toBe('Rosemary Oil');
    expect($copy->owner_type)->toBe(OwnerType::User);
    expect($copy->owner_id)->toBe($user->id);
    expect($copy->featured_image_path)->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/IngredientsIndexDuplicationTest.php`
Expected: FAIL — routes do not exist, "Duplicate platform ingredient" text not found.

- [ ] **Step 3: Add the routes**

In `routes/web.php`, inside the `ingredients.` route group, add:

```php
Route::get('/search-platform', 'searchPlatform')->name('search-platform');
Route::post('/duplicate', 'duplicate')->name('duplicate');
```

- [ ] **Step 4: Add the controller methods**

In `app/Http/Controllers/IngredientController.php`, add:

```php
public function searchPlatform(Request $request)
{
    $query = (string) $request->query('q', '');

    $results = Ingredient::query()
        ->whereNull('owner_type')
        ->where('is_active', true)
        ->when(filled($query), fn ($q) => $q->where(function ($q) use ($query) {
            $q->where('display_name', 'ilike', "%{$query}%")
              ->orWhere('inci_name', 'ilike', "%{$query}%");
        }))
        ->orderBy('display_name')
        ->limit(20)
        ->get()
        ->map(fn (Ingredient $ingredient) => [
            'id' => $ingredient->id,
            'name' => $ingredient->display_name,
            'inci_name' => $ingredient->inci_name,
            'category' => $ingredient->category?->getLabel(),
        ]);

    return response()->json($results);
}

public function duplicate(Request $request)
{
    $user = $request->user();

    if (! $user instanceof User) {
        return response()->json(['ok' => false, 'message' => 'Sign in required.'], 403);
    }

    $validated = $request->validate([
        'ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
    ]);

    $source = Ingredient::query()->findOrFail($validated['ingredient_id']);

    $copy = app(UserIngredientAuthoringService::class)->duplicate($source, $user);

    return response()->json([
        'ok' => true,
        'ingredient_id' => $copy->id,
        'redirect' => route('ingredients.edit', $copy->id),
    ]);
}
```

Add import: `use App\Services\UserIngredientAuthoringService;`

- [ ] **Step 5: Create the duplication modal partial**

Create `resources/views/livewire/dashboard/partials/duplicate-ingredient-modal.blade.php`:

```blade
<div x-data="{
    open: false,
    query: '',
    results: [],
    loading: false,
    selected: null,

    async search() {
        if (this.query.length < 2) {
            this.results = [];
            return;
        }
        this.loading = true;
        const response = await fetch('{{ route('ingredients.search-platform') }}?q=' + encodeURIComponent(this.query), {
            headers: { 'Accept': 'application/json' }
        });
        this.results = await response.json();
        this.loading = false;
    },

    async duplicate(ingredientId) {
        const response = await fetch('{{ route('ingredients.duplicate') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ ingredient_id: ingredientId }),
        });
        const data = await response.json();
        if (data.ok && data.redirect) {
            window.location.href = data.redirect;
        }
    }
}" @keydown.escape.window="open = false">
    <button type="button" @click="open = true" class="inline-flex items-center gap-2 rounded-full border border-[var(--color-line)] px-4 py-2 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-white">
        Duplicate platform ingredient
    </button>

    <template x-if="open">
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-[color:oklch(from_var(--color-surface-strong)_l_c_h_/_0.55)] px-4 py-6" @click.self="open = false">
            <div class="w-full max-w-lg rounded-xl bg-[var(--color-panel)] shadow-[0_2px_4px_rgba(60,50,30,0.04),0_12px_24px_rgba(60,50,30,0.08)] p-6" @click.stop>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-[0.6875rem] font-medium tracking-[0.05em] text-[var(--color-ink-soft)] uppercase">Duplicate</p>
                        <h3 class="mt-1 text-lg font-semibold text-[var(--color-ink-strong)]">Duplicate a platform ingredient</h3>
                        <p class="mt-2 text-sm text-[var(--color-ink-soft)]">Search the platform catalog, then duplicate an ingredient to customize allergens, IFRA, or composition data.</p>
                    </div>
                    <button type="button" @click="open = false" class="rounded-full border border-[var(--color-line)] px-3 py-1.5 text-sm font-medium text-[var(--color-ink-soft)] transition hover:bg-[var(--color-panel)]">Cancel</button>
                </div>

                <div class="mt-5">
                    <input
                        x-model="query"
                        @input.debounce.300ms="search()"
                        type="text"
                        placeholder="Search by name or INCI..."
                        class="w-full rounded-lg border border-[var(--color-line)] bg-[var(--color-field)] px-4 py-3 text-sm text-[var(--color-ink-strong)] outline outline-1 outline-[var(--color-field-outline)] transition focus:outline-2 focus:outline-[var(--color-accent)]"
                        autofocus
                    />
                </div>

                <div class="mt-4 max-h-64 overflow-y-auto divide-y divide-[var(--color-line)] rounded-lg border border-[var(--color-line)]">
                    <template x-if="loading">
                        <div class="px-4 py-6 text-center text-sm text-[var(--color-ink-soft)]">Searching...</div>
                    </template>

                    <template x-if="!loading && results.length === 0 && query.length >= 2">
                        <div class="px-4 py-6 text-center text-sm text-[var(--color-ink-soft)]">No matching platform ingredients found.</div>
                    </template>

                    <template x-if="!loading && results.length === 0 && query.length < 2">
                        <div class="px-4 py-6 text-center text-sm text-[var(--color-ink-soft)]">Type at least 2 characters to search.</div>
                    </template>

                    <template x-for="item in results" :key="item.id">
                        <button type="button" @click="duplicate(item.id)" class="flex w-full items-center justify-between px-4 py-3 text-left transition hover:bg-[var(--color-panel)]">
                            <div>
                                <p class="text-sm font-medium text-[var(--color-ink-strong)]" x-text="item.name"></p>
                                <p class="mt-0.5 text-xs text-[var(--color-ink-soft)]" x-text="[item.inci_name, item.category].filter(Boolean).join(' · ')"></p>
                            </div>
                            <span class="shrink-0 text-xs font-medium text-[var(--color-accent)]">Duplicate</span>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </template>
</div>
```

- [ ] **Step 6: Include the modal in the ingredients index view**

In `resources/views/livewire/dashboard/ingredients-index.blade.php`, add the modal in the header section alongside the existing "Back to dashboard" link. Add the modal partial after the table section:

After the "Back to dashboard" link (line 13), add:
```blade
@include('livewire.dashboard.partials.duplicate-ingredient-modal')
```

- [ ] **Step 7: Run the duplication UI test**

Run: `php artisan test --compact tests/Feature/IngredientsIndexDuplicationTest.php`
Expected: PASS — all three tests green.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/IngredientController.php routes/web.php resources/views/livewire/dashboard/partials/duplicate-ingredient-modal.blade.php resources/views/livewire/dashboard/ingredients-index.blade.php tests/Feature/IngredientsIndexDuplicationTest.php
git commit -m "feat: add ingredient duplication UI to ingredients page"
```

---

### Task 5: Add Compliance Disclaimer

**Files:**
- Modify: `app/Services/InciGenerationService.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php`
- Modify: `resources/js/recipe-workbench/sections/presentation-section.js`

- [ ] **Step 1: Add ownership flag to INCI generation rows**

In `app/Services/InciGenerationService.php`, find where `source_ingredients` is populated for ingredient rows. This happens around line 671:

```php
$rowsByLabel[$labelKey]['source_ingredients'][] = $context['ingredient_name'];
```

After this line, add:
```php
if (! isset($rowsByLabel[$labelKey]['source_is_user_owned'])) {
    $rowsByLabel[$labelKey]['source_is_user_owned'] = [];
}
$rowsByLabel[$labelKey]['source_is_user_owned'][] = (bool) ($context['is_user_owned'] ?? false);
```

The `$context` array needs to carry the ownership flag. Find where `$context['ingredient_name']` is set (in the method that builds contributions) and add the ownership information. The context is built from formula items, which have ingredient IDs. Check each ingredient's `owner_type` to determine if it is user-owned.

To pass the flag through, add to the contribution context where ingredient data is assembled:

```php
$context['is_user_owned'] = $ingredient->owner_type !== null;
```

Similarly, update the declaration rows around line 855 to include `source_is_user_owned`.

Then in the final row-building step (around line 692), add `source_is_user_owned` to each output row alongside `source_ingredients`.

- [ ] **Step 2: Pass the flag through in the presentation section**

In `resources/js/recipe-workbench/sections/presentation-section.js`, in the `drySoapIngredientRows` getter (line 169), the spread `...row` already carries any new fields from the backend. No change needed if `source_is_user_owned` is included in the payload.

Verify by checking that the `drySoapAllergenRows` getter (line 232) also preserves the field through its filter — it uses `.filter()` which preserves all properties.

- [ ] **Step 3: Render the colored dot in the output tab**

In `resources/views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php`, update the "Sources" column in the ingredient basis table (line 57):

Replace:
```blade
<td class="px-5 py-4 align-top text-[var(--color-ink-soft)]" x-text="row.source_ingredients.join(', ')"></td>
```

With:
```blade
<td class="px-5 py-4 align-top text-[var(--color-ink-soft)]">
    <template x-for="(source, idx) in row.source_ingredients" :key="idx">
        <span class="inline-flex items-center gap-1">
            <span x-show="row.source_is_user_owned?.[idx]" class="inline-block size-1.5 rounded-full bg-amber-400" title="Ingredient not curated by the platform"></span>
            <span x-text="source"></span>
        </span>
    </template>
</td>
```

Apply the same change to the "Sources" column in the declared allergens table (line 102).

- [ ] **Step 4: Add a small legend below the output tab**

After the allergens section closing tag (line 115), before the final `</div>`, add:

```blade
<template x-if="drySoapIngredientRows.some(row => row.source_is_user_owned?.some(Boolean)) || drySoapAllergenRows.some(row => row.source_is_user_owned?.some(Boolean))">
    <p class="rounded-lg bg-amber-50 px-4 py-2.5 text-xs text-amber-700">
        <span class="inline-block size-1.5 rounded-full bg-amber-400 mr-1"></span>
        Ingredient not curated by the platform. Compliance data is user-maintained.
    </p>
</template>
```

- [ ] **Step 5: Build frontend and run tests**

Run: `npm run build`
Run: `php artisan test --compact`
Expected: all tests pass, Vite build succeeds.

- [ ] **Step 6: Commit**

```bash
git add app/Services/InciGenerationService.php resources/views/livewire/dashboard/partials/recipe-workbench/output-tab.blade.php resources/js/recipe-workbench/sections/presentation-section.js
git commit -m "feat: add user-owned ingredient disclaimer to output tab"
```

---

### Task 6: Final Verification

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test --compact`
Expected: all tests pass.

- [ ] **Step 2: Run Pint on touched files**

Run: `vendor/bin/pint --dirty --format agent`
Expected: `{"result":"pass"}`

- [ ] **Step 3: Build frontend assets**

Run: `npm run build`
Expected: Vite build succeeds with exit code 0.

- [ ] **Step 4: Fix any failures minimally and rerun**

If any step fails, fix only the affected files and rerun the exact failing command.

- [ ] **Step 5: Final commit if fixes were needed**

```bash
git add -A
git commit -m "fix: address verification failures"
```

---

## Self-Review Checklist

- Spec coverage:
  - Dead column removal: Task 1
  - Duplication service: Task 2
  - Priced ingredients section: Task 3
  - Duplication UI: Task 4
  - Compliance disclaimer: Task 5
  - Final verification: Task 6
- Placeholder scan: no TBD/TODO/fill-in-later found
- Type consistency:
  - `source_is_user_owned` is used consistently as a boolean array in Task 5 (service, JS, Blade)
  - `UserIngredientAuthoringService::duplicate()` is called from the controller in Task 4
  - Route names `ingredients.search-platform`, `ingredients.duplicate`, `ingredients.update-price` are consistent across routes, controller, Blade, and tests
