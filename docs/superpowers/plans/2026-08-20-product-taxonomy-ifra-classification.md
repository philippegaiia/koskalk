# Product Taxonomy and IFRA Classification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give makers a short, clear finished-product taxonomy for Soap, Cosmetics, and Home creation while automatically suggesting an editable, optional, amendment-aware IFRA category that never blocks formula saving.

**Architecture:** The existing `recipes` row remains the domain Product and owns one calculation family and one current product type. Product types belong to a user-facing area/category hierarchy and may support more than one calculation family; the Saved Formula (`recipe_versions`) records the actual IFRA choice and the amendment-aware mapping used to suggest it. IFRA amendment timelines are versioned reference data, but “new/existing creation” is not stored on a finished Product because IFRA defines that status for fragrance mixtures leaving a fragrance house.

**Tech Stack:** PHP 8.5, Laravel 13.26, PostgreSQL in production, SQLite in tests, Livewire 4.4, Filament 5.7 form components, Alpine.js, Tailwind CSS 4, Pest 4.7.

---

## Locked design decisions

1. **Product placement:** the domain Product is still implemented by `App\Models\Recipe` / `recipes`. Do not rename the aggregate during this work.
2. **Classification ownership:** `recipes.product_family_id` and `recipes.product_type_id` are Product properties. Do not copy them to every `recipe_versions` row.
3. **History rule:** the calculation family never changes after Product creation. The product type may change until the first Saved Formula exists, then becomes immutable. A materially different finished-product type is created by duplication as a new Product.
4. **Formula engines are not navigation:** `ProductFamily` remains the calculation engine (`initial_oils` or `total_formula`). A product type may support both engines. This is how a cleansing bar can be made from oils + lye, soap noodles, melt-and-pour, or syndets without creating misleading user categories.
5. **Creation entry points are shortcuts, not database enums:**
   - **Soap** — `soap` family; show compatible Personal care and Home types.
   - **Cosmetics** — `cosmetic` family; show compatible Personal care types.
   - **Home** — `cosmetic` family; show compatible Home & household types.
6. **IFRA is optional guidance:** saving, creating a Saved Formula, locking, printing, and exporting do not require an IFRA choice, regulatory regime, allergens, or aromatic materials.
7. **Automatic is distinct from manual:** a formula starts in automatic IFRA mode. The backend resolves the product-type default even when no aromatic material is present. If the user chooses or clears a category, the formula switches to manual mode and the backend respects that choice.
8. **One actual IFRA category per Saved Formula in v1:** product types may offer several candidates and guidance, but this change does not implement multi-use finished products. The modal must explain that products marketed for several uses require professional review of every applicable IFRA category.
9. **IFRA 51 is the only production seed:** as of 2026-08-20 the 52nd consultation is closed but formal Notification is only expected near the end of November 2026. Do not seed consultation categories or dates as final.
10. **Amendment timelines:** dates are stored per standard kind (`prohibition`, `restriction_specification`) and fragrance creation track (`new`, `existing`). The app may display these dates but must not infer the track from a finished Product.
11. **Other is a deliberate escape hatch:** `Other cosmetics` and `Other home product` have no automatic IFRA default. They expose the complete active IFRA list only when compliance is opened.
12. **No FDA/CPNP user tree in this release:** future market mappings can attach to the canonical Product Type. Regulatory status by market remains separate from finished-product navigation.
13. **Laravel conventions are mandatory:** use Laravel 13 Schema Builder and Eloquent defaults wherever they express the design. Generate new PHP classes, migrations, seeders, tests, models, factories, and views with their Artisan `make:*` commands. Table names are plural snake case; pure many-to-many pivots use alphabetical singular model names, conventional foreign keys, no artificial ID, and a composite primary key; `belongsToMany()` relies on those inferred names. Name each `BelongsTo` method after its foreign-key stem, such as `ifraAmendment()` for `ifra_amendment_id`, so custom key arguments are unnecessary. Use raw SQL only where Schema Builder has no equivalent, currently the PostgreSQL/SQLite partial unique indexes. Add explicit indexes only for demonstrated relationship/filter directions not already covered by a primary or unique index, and reverse them with `dropIndex()`. Every foreign key declares its delete behavior and every migration has a real `down()` path.

## Canonical user-facing taxonomy

The initial seed is intentionally broad enough for small makers but short enough to scan. `Families` lists the calculation engines a type may use. `IFRA 51` is the default suggestion; `—` means no automatic suggestion.

| Area | Category | Product type | Slug | Families | IFRA 51 |
| --- | --- | --- | --- | --- | --- |
| Personal care | Body cleansing | Bar soap / cleansing bar | `bar-soap-cleansing-bar` | soap, cosmetic | 9 |
| Personal care | Body cleansing | Liquid soap / body wash | `liquid-soap-body-wash` | soap, cosmetic | 9 |
| Personal care | Body cleansing | Face cleanser | `face-cleanser` | cosmetic | 9 |
| Personal care | Body cleansing | Bath salts / soaks / bombs | `bath-salts-soaks-bombs` | cosmetic | 9 |
| Personal care | Body cleansing | Shaving soap / cream | `shaving-soap-cream` | soap, cosmetic | 9 |
| Personal care | Skin care | Body cream / lotion / oil | `body-cream-lotion-oil` | cosmetic | 5A |
| Personal care | Skin care | Face cream / serum / toner | `face-cream-serum-toner` | cosmetic | 5B |
| Personal care | Skin care | Hand / nail care | `hand-nail-care` | cosmetic | 5C |
| Personal care | Skin care | Foot cream / powder | `foot-cream-powder` | cosmetic | 5A |
| Personal care | Skin care | Face mask | `face-mask` | cosmetic | 3 |
| Personal care | Skin care | Baby cream / oil / powder | `baby-cream-oil-powder` | cosmetic | 5D |
| Personal care | Skin care | Massage / body oil | `massage-body-oil` | cosmetic | 5A |
| Personal care | Skin care | Skin-contact massage candle | `skin-contact-massage-candle` | cosmetic | 5A |
| Personal care | Hair care | Shampoo | `shampoo` | cosmetic | 9 |
| Personal care | Hair care | Rinse-off conditioner | `rinse-off-conditioner` | cosmetic | 9 |
| Personal care | Hair care | Leave-in / styling / hair oil | `leave-in-styling-hair-oil` | cosmetic | 7B |
| Personal care | Hair care | Rinse-off hair dye / chemical treatment | `rinse-off-hair-chemical-treatment` | cosmetic | 7A |
| Personal care | Lips & oral care | Lip product | `lip-product` | cosmetic | 1 |
| Personal care | Lips & oral care | Toothpaste / mouthwash | `toothpaste-mouthwash` | cosmetic | 6 |
| Personal care | Deodorant & fragrance | Deodorant / antiperspirant | `deodorant-antiperspirant` | cosmetic | 2 |
| Personal care | Deodorant & fragrance | Body mist / body spray | `body-mist-spray` | cosmetic | 2; alternative 4 only with explicit no-axilla/no-deodorant positioning |
| Personal care | Deodorant & fragrance | Fine fragrance / solid perfume | `fine-fragrance-solid-perfume` | cosmetic | 4 |
| Personal care | Grooming | Aftershave splash | `aftershave-splash` | cosmetic | 4 |
| Personal care | Grooming | Aftershave cream / balm | `aftershave-cream-balm` | cosmetic | 5B |
| Personal care | Makeup | Face / eye makeup | `face-eye-makeup` | cosmetic | 3 |
| Personal care | Makeup | Makeup remover | `makeup-remover` | cosmetic | 3 |
| Personal care | Sun & tan care | Body sun / self-tan care | `body-sun-self-tan-care` | cosmetic | 5A |
| Personal care | Sun & tan care | Face sun / self-tan care | `face-sun-self-tan-care` | cosmetic | 5B |
| Personal care | Other | Other cosmetics | `other-cosmetics` | cosmetic | — |
| Home & household | Home fragrance | Candle / wax melt | `candle-wax-melt` | cosmetic | 12 |
| Home & household | Home fragrance | Reed diffuser / liquid refill | `reed-diffuser-refill` | cosmetic | 10A |
| Home & household | Home fragrance | Room / air-freshener spray | `room-air-freshener-spray` | cosmetic | 10B |
| Home & household | Home fragrance | Pillow spray | `pillow-spray` | cosmetic | 11B |
| Home & household | Home fragrance | Fabric / linen spray | `fabric-linen-spray` | cosmetic | 10A |
| Home & household | Home fragrance | Incense / passive air fragrance | `incense-passive-air-fragrance` | cosmetic | 12 |
| Home & household | Dish care | Hand dishwashing soap / detergent | `hand-dishwashing-product` | soap, cosmetic | 10A |
| Home & household | Dish care | Automatic dishwasher product | `automatic-dishwasher-product` | cosmetic | 12 |
| Home & household | Laundry | Hand-wash laundry soap / detergent | `hand-wash-laundry-product` | soap, cosmetic | 10A |
| Home & household | Laundry | Machine laundry liquid / powder | `machine-laundry-detergent` | cosmetic | 10A |
| Home & household | Laundry | Laundry pre-treatment / stain remover | `laundry-pre-treatment` | cosmetic | 10A |
| Home & household | Laundry | Fabric softener | `fabric-softener` | cosmetic | 10A |
| Home & household | Laundry | Dryer sheet / scent beads | `dryer-sheet-scent-beads` | cosmetic | 12 |
| Home & household | Surface & toilet care | Hard-surface cleaner | `hard-surface-cleaner` | soap, cosmetic | 10A |
| Home & household | Surface & toilet care | Toilet gel / rim block | `toilet-gel-rim-block` | cosmetic | 12 |
| Home & household | Other | Other home product | `other-home-product` | cosmetic | — |

Classification follows intended finished-product use, not ingredients or manufacturing method. A body cleansing bar remains IFRA 9 whether it is saponified, made from noodles, melt-and-pour, or a syndet. A separately marketed hand-dishwashing bar is IFRA 10A. An ordinary candle is 12; a skin-contact massage candle is a separate type at 5A.

## IFRA seed provenance

- Current notified category/product list: [IFRA 51st Guidance, Tables 11–12](https://ifrafragrance.org/docs/default-source/51st-amendment/ifra-51st-amendment---guidance-for-the-use-of-ifra-standards.pdf?sfvrsn=79750005_2).
- 51st Notification and concrete milestones: [IFRA 51st Notification Letter](https://ifrafragrance.org/docs/default-source/51st-amendment/ifra-51st-amendment---notification-letter.pdf?sfvrsn=aa518b6a_2).
- 52nd remains non-final: [IFRA consultation closure notice](https://ifrafragrance.org/latest-updates/ifra-news/ifra-52nd-amendment-consultation-closed).

## Laravel implementation references

- [Laravel 13 many-to-many relationships](https://laravel.com/docs/13.x/eloquent-relationships#many-to-many-table-structure): alphabetical singular pivot naming, conventional foreign keys, `belongsToMany()`, and `withTimestamps()`.
- [Laravel 13 migrations](https://laravel.com/docs/13.x/migrations): Artisan-generated anonymous migrations, Schema Builder, explicit foreign-key behavior, index operations, column changes, and reversible `down()` methods.

## Target relation model

```text
ProductArea 1 ── * ProductCategory 1 ── * ProductType
                                             * ── * ProductFamily
                                             * ── * IfraProductCategory
                                                    through ProductTypeIfraCategory
                                                    scoped by IfraAmendment

Recipe (domain Product) * ── 0..1 ProductType (null only for legacy Products)
Recipe (domain Product) * ── 1 ProductFamily
Recipe 1 ── * RecipeVersion
RecipeVersion * ── 0..1 ProductTypeIfraCategory
RecipeVersion * ── 0..1 IfraProductCategory
RecipeVersion * ── 0..1 IfraAmendment

IfraAmendment 1 ── * IfraAmendmentMilestone
IfraCertificate * ── 0..1 IfraAmendment
```

The external seam for IFRA suggestion is one deep module:

```php
final class ProductTypeIfraOptionsBuilder
{
    /**
     * @return array{
     *     amendment: array{id:int, code:string, status:string}|null,
     *     default_category_id: int|null,
     *     default_mapping_id: int|null,
     *     options: array<int, array{
     *         mapping_id:int|null,
     *         id:int,
     *         code:string,
     *         name:string,
     *         short_name:?string,
     *         description:?string,
     *         guidance:?string,
     *         is_default:bool
     *     }>,
     *     all_categories: array<int, array{
     *         id:int,
     *         code:string,
     *         name:string,
     *         short_name:?string,
     *         description:?string
     *     }>,
     *     milestones: array<int, array{
     *         standard_kind:string,
     *         creation_track:string,
     *         effective_on:string
     *     }>
     * }
     */
    public function build(?ProductType $productType, ?IfraProductCategory $selected = null): array;
}
```

Callers do not query amendment rows, mappings, fallback categories, or defaults themselves.

## File map

### Create

- `app/Enums/IfraAmendmentStatus.php`
- `app/Enums/IfraCategorySelectionMode.php`
- `app/Enums/IfraCreationTrack.php`
- `app/Enums/IfraStandardKind.php`
- `app/Models/ProductArea.php`
- `app/Models/ProductCategory.php`
- `app/Models/ProductTypeIfraCategory.php`
- `app/Models/IfraAmendment.php`
- `app/Models/IfraAmendmentMilestone.php`
- `app/Services/ProductClassificationService.php`
- `app/Services/ProductCreationCatalog.php`
- `database/factories/ProductAreaFactory.php`
- `database/factories/ProductCategoryFactory.php`
- `database/factories/ProductTypeIfraCategoryFactory.php`
- `database/factories/IfraAmendmentFactory.php`
- `database/factories/IfraAmendmentMilestoneFactory.php`
- `database/migrations/2026_08_20_000001_expand_product_taxonomy.php`
- `database/migrations/2026_08_20_000002_create_ifra_amendments_and_type_mappings.php`
- `database/migrations/2026_08_20_000003_add_ifra_resolution_to_recipe_versions_and_certificates.php`
- `database/seeders/IfraAmendmentSeeder.php`
- `database/seeders/ProductTaxonomySeeder.php`
- `database/seeders/ProductTypeIfraCategorySeeder.php`
- `resources/views/recipes/create-start.blade.php`
- `resources/views/livewire/dashboard/partials/recipe-workbench/ifra-category-modal.blade.php`
- `tests/Feature/ProductTaxonomyFoundationTest.php`
- `tests/Feature/ProductClassificationServiceTest.php`
- `tests/Feature/ProductTypeIfraOptionsBuilderTest.php`
- `tests/Feature/ProductCreationFlowTest.php`

### Modify

- `app/Models/ProductFamily.php`
- `app/Models/ProductType.php`
- `app/Models/IfraProductCategory.php`
- `app/Models/IfraCertificate.php`
- `app/Models/Recipe.php`
- `app/Models/RecipeVersion.php`
- `app/Services/RecipeWorkbenchIfraOptionsBuilder.php` — rename class/file to `ProductTypeIfraOptionsBuilder.php`; update all callers.
- `app/Services/RecipeWorkbenchContextResolver.php`
- `app/Services/RecipeWorkbenchPayloadNormalizer.php`
- `app/Services/RecipeVersionRecordService.php`
- `app/Services/RecipeWorkbenchDraftPayloadMapper.php`
- `app/Services/RecipeWorkbenchVersionPayloadMapper.php`
- `app/Services/RecipeWorkbenchViewDataBuilder.php`
- `app/Http/Controllers/RecipeController.php`
- `app/Livewire/Dashboard/RecipeWorkbench.php`
- `app/Livewire/Dashboard/RecipesIndex.php`
- `resources/js/recipe-workbench/component.js`
- `resources/js/recipe-workbench/payload.js`
- `resources/js/recipe-workbench/snapshot.js`
- `resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php`
- `resources/views/livewire/dashboard/recipes-index.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/recipes/product-type-selector.blade.php` — replace the currently unused cosmetic-only markup in place; do not create a second selector.
- `routes/web.php`
- `lang/en/products.php`
- `lang/en/workbench.php`
- `database/factories/ProductTypeFactory.php`
- `database/factories/IfraCertificateFactory.php`
- `database/seeders/DatabaseSeeder.php`
- `app/Filament/Resources/ProductTypes/Schemas/ProductTypeForm.php`
- `app/Filament/Resources/ProductTypes/Tables/ProductTypesTable.php`
- `app/Filament/Resources/IfraCertificates/Schemas/IfraCertificateForm.php`
- `app/Filament/Resources/IfraCertificates/Tables/IfraCertificatesTable.php`
- focused existing tests named in the tasks below.

### Retire from runtime, but retain tables/columns for one safe deployment

- `app/Models/ProductFamilyIfraCategory.php`
- `database/factories/ProductFamilyIfraCategoryFactory.php`
- `product_family_ifra_categories`
- `product_types.product_family_id`
- `product_types.default_ifra_product_category_id`

Do not drop these legacy database structures in this implementation. Stop all runtime reads/writes, add deprecation coverage, and remove them in a later contract migration after deployed data has been observed. This preserves rollback and avoids an irreversible transformation of any private installation's legacy family-level mappings.

---

### Task 1: Add the taxonomy and amendment schema

**Files:**
- Create: the three migrations listed above
- Create: `app/Enums/IfraAmendmentStatus.php`
- Create: `app/Enums/IfraCategorySelectionMode.php`
- Create: `app/Enums/IfraCreationTrack.php`
- Create: `app/Enums/IfraStandardKind.php`
- Test: `tests/Feature/ProductTaxonomyFoundationTest.php`

- [ ] **Step 1: Generate the migrations, enums, and Pest test with Artisan**

Run:

```bash
php artisan make:migration expand_product_taxonomy --no-interaction
php artisan make:migration create_ifra_amendments_and_type_mappings --no-interaction
php artisan make:migration add_ifra_resolution_to_recipe_versions_and_certificates --no-interaction
php artisan make:enum IfraAmendmentStatus --no-interaction
php artisan make:enum IfraCategorySelectionMode --no-interaction
php artisan make:enum IfraCreationTrack --no-interaction
php artisan make:enum IfraStandardKind --no-interaction
php artisan make:test --pest ProductTaxonomyFoundationTest --no-interaction
```

Expected: three anonymous migrations, four enums in `app/Enums`, and one test in `tests/Feature`.

- [ ] **Step 2: Write failing schema and constraint tests**

Add tests that assert:

```php
uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('stores user taxonomy independently from calculation families', function (): void {
    expect(Schema::hasColumns('product_areas', ['name', 'slug', 'sort_order', 'is_active']))->toBeTrue()
        ->and(Schema::hasColumns('product_categories', ['product_area_id', 'name', 'slug', 'sort_order', 'is_active']))->toBeTrue()
        ->and(Schema::hasColumns('product_family_product_type', ['product_family_id', 'product_type_id']))->toBeTrue()
        ->and(Schema::hasColumn('product_family_product_type', 'id'))->toBeFalse()
        ->and(Schema::hasColumn('product_types', 'product_category_id'))->toBeTrue()
        ->and(Schema::hasIndex('product_family_product_type', ['product_type_id']))->toBeTrue()
        ->and(Schema::hasIndex('product_types', ['product_category_id']))->toBeTrue()
        ->and(Schema::hasIndex('recipes', ['product_type_id']))->toBeTrue();
});

it('stores amendment milestones without putting a creation track on formulas', function (): void {
    expect(Schema::hasColumns('ifra_amendments', ['code', 'status', 'notification_date', 'source_url']))->toBeTrue()
        ->and(Schema::hasColumns('ifra_amendment_milestones', ['ifra_amendment_id', 'standard_kind', 'creation_track', 'effective_on']))->toBeTrue()
        ->and(Schema::hasColumns('product_type_ifra_categories', ['product_type_id', 'ifra_amendment_id', 'ifra_product_category_id', 'is_default', 'guidance', 'source_url', 'sort_order', 'is_active']))->toBeTrue()
        ->and(Schema::hasColumns('recipe_versions', ['ifra_amendment_id', 'product_type_ifra_category_id', 'ifra_category_selection_mode']))->toBeTrue()
        ->and(Schema::hasIndex('recipe_versions', ['ifra_amendment_id']))->toBeTrue()
        ->and(Schema::hasIndex('recipe_versions', ['product_type_ifra_category_id']))->toBeTrue()
        ->and(Schema::hasIndex('ifra_certificates', ['ifra_amendment_id']))->toBeTrue()
        ->and(Schema::hasColumn('recipe_versions', 'ifra_creation_track'))->toBeFalse();
});
```

Add a raw insert boundary test showing a second default for the same `(product_type_id, ifra_amendment_id)` is rejected while two non-default candidates are allowed.

Add raw schema boundary tests showing:

- `product_types.product_family_id` accepts null after expansion;
- the same active slug is rejected across two different legacy families;
- an inactive historical row may retain the same slug as an active canonical row;
- the conventional `product_family_product_type` pivot supports attaching one type to both calculation families and rejects a duplicate pair through its composite primary key.

- [ ] **Step 3: Run the test and verify it fails**

Run:

```bash
php artisan test --compact tests/Feature/ProductTaxonomyFoundationTest.php
```

Expected: FAIL because the new tables and columns do not exist.

- [ ] **Step 4: Implement the enum values**

Use these exact backed-string cases:

```php
enum IfraAmendmentStatus: string
{
    case Consultation = 'consultation';
    case Notified = 'notified';
    case Superseded = 'superseded';
}

enum IfraCategorySelectionMode: string
{
    case Automatic = 'automatic';
    case Manual = 'manual';
    case Legacy = 'legacy';
}

enum IfraCreationTrack: string
{
    case New = 'new';
    case Existing = 'existing';
}

enum IfraStandardKind: string
{
    case Prohibition = 'prohibition';
    case RestrictionSpecification = 'restriction_specification';
}
```

- [ ] **Step 5: Implement the expansion migrations**

Keep the anonymous classes generated by Artisan. Import Laravel's migration, schema, and query-building types directly; do not use Eloquent models in migrations:

```php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
```

The migrations must create:

```php
Schema::create('product_areas', function (Blueprint $table): void {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->unsignedInteger('sort_order')->default(10);
    $table->boolean('is_active')->default(true);
    $table->text('description')->nullable();
    $table->timestamps();
});

Schema::create('product_categories', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('product_area_id')->constrained()->restrictOnDelete();
    $table->string('name');
    $table->string('slug');
    $table->unsignedInteger('sort_order')->default(10);
    $table->boolean('is_active')->default(true);
    $table->text('description')->nullable();
    $table->timestamps();
    $table->unique(['product_area_id', 'slug']);
});

Schema::table('product_types', function (Blueprint $table): void {
    $table->foreignId('product_category_id')->nullable()->constrained()->restrictOnDelete();
    $table->index('product_category_id');
});

Schema::create('product_family_product_type', function (Blueprint $table): void {
    $table->foreignId('product_family_id')->constrained()->cascadeOnDelete();
    $table->foreignId('product_type_id')->constrained()->cascadeOnDelete();
    $table->timestamps();

    $table->primary(['product_family_id', 'product_type_id']);
    $table->index('product_type_id');
});

Schema::table('recipes', function (Blueprint $table): void {
    $table->index('product_type_id');
});
```

The pivot primary key already indexes the `product_family_id` leading column, so add only the reverse `product_type_id` index. The existing recipes workspace index and the new Product Type index may be combined by PostgreSQL when both tenant and type filters are present; do not add an unproven composite index in this release.

Backfill the conventional pivot before making the legacy family nullable. Use the query builder inside the migration rather than Eloquent models, because migrations must remain independent of future model behavior:

```php
DB::table('product_types')
    ->whereNotNull('product_family_id')
    ->select(['id', 'product_family_id'])
    ->orderBy('id')
    ->eachById(function (object $productType): void {
        DB::table('product_family_product_type')->insertOrIgnore([
            'product_family_id' => $productType->product_family_id,
            'product_type_id' => $productType->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
```

Before changing the legacy key, query for duplicate active slugs across families. Abort instead of silently merging, renaming, or deactivating private-installation data:

```php
$activeSlugCollisions = DB::table('product_types')
    ->where('is_active', true)
    ->orderBy('slug')
    ->pluck('slug')
    ->duplicates()
    ->unique()
    ->values();

if ($activeSlugCollisions->isNotEmpty()) {
    throw new LogicException(
        'Resolve duplicate active Product Type slugs before migrating: '.$activeSlugCollisions->implode(', '),
    );
}
```

Then drop the existing `product_types_product_family_id_slug_unique` composite index, make the retained `product_types.product_family_id` column nullable, and add a PostgreSQL/SQLite partial unique index named `product_types_active_slug_unique` on active slugs.

The runtime invariant is global uniqueness among active Product Type slugs. Inactive historical duplicates may remain loadable by ID. Do not classify or deactivate legacy types in the migration; reference seeding owns catalog content.

Use Schema Builder for the column/index change and driver-guarded raw SQL only for the partial index that Schema Builder cannot express:

```php
Schema::table('product_types', function (Blueprint $table): void {
    $table->dropUnique('product_types_product_family_id_slug_unique');
    $table->foreignId('product_family_id')->nullable()->change();
});

$activeSlugIndex = 'product_types_active_slug_unique';

if (DB::getDriverName() === 'pgsql') {
    DB::statement(
        "CREATE UNIQUE INDEX {$activeSlugIndex} ON product_types (slug) WHERE is_active = TRUE",
    );
}

if (DB::getDriverName() === 'sqlite') {
    DB::statement(
        "CREATE UNIQUE INDEX {$activeSlugIndex} ON product_types (slug) WHERE is_active = 1",
    );
}

if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
    throw new LogicException('The Product Type active-slug index is not implemented for this database driver.');
}
```

Create amendment tables and mappings with a normal unique key plus a PostgreSQL/SQLite partial unique index:

```php
Schema::create('ifra_amendments', function (Blueprint $table): void {
    $table->id();
    $table->string('code', 16)->unique();
    $table->string('status', 32);
    $table->date('notification_date')->nullable();
    $table->string('source_url', 2048)->nullable();
    $table->text('notes')->nullable();
    $table->timestamps();
});

Schema::create('ifra_amendment_milestones', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('ifra_amendment_id')->constrained()->cascadeOnDelete();
    $table->string('standard_kind', 32);
    $table->string('creation_track', 16);
    $table->date('effective_on');
    $table->timestamps();
    $table->unique(['ifra_amendment_id', 'standard_kind', 'creation_track'], 'ifra_amendment_milestones_scope_unique');
});

Schema::create('product_type_ifra_categories', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('product_type_id')->constrained()->cascadeOnDelete();
    $table->foreignId('ifra_amendment_id')->constrained()->cascadeOnDelete();
    $table->foreignId('ifra_product_category_id')->constrained()->cascadeOnDelete();
    $table->boolean('is_default')->default(false);
    $table->text('guidance')->nullable();
    $table->string('source_url', 2048)->nullable();
    $table->unsignedInteger('sort_order')->default(10);
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    $table->unique(['product_type_id', 'ifra_amendment_id', 'ifra_product_category_id'], 'product_type_ifra_category_unique');
});

$defaultMappingIndex = 'product_type_ifra_default_unique';

if (DB::getDriverName() === 'pgsql') {
    DB::statement(
        "CREATE UNIQUE INDEX {$defaultMappingIndex} ON product_type_ifra_categories (product_type_id, ifra_amendment_id) WHERE is_default = TRUE",
    );
}

if (DB::getDriverName() === 'sqlite') {
    DB::statement(
        "CREATE UNIQUE INDEX {$defaultMappingIndex} ON product_type_ifra_categories (product_type_id, ifra_amendment_id) WHERE is_default = 1",
    );
}

if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
    throw new LogicException('The IFRA default-mapping index is not implemented for this database driver.');
}
```

Add nullable FKs and source mode to `recipe_versions`, and an amendment FK plus preserved raw source label to certificates:

```php
Schema::table('recipe_versions', function (Blueprint $table): void {
    $table->foreignId('ifra_amendment_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('product_type_ifra_category_id')->nullable()->constrained()->nullOnDelete();
    $table->string('ifra_category_selection_mode', 16)->default('automatic');
    $table->index('ifra_amendment_id');
    $table->index('product_type_ifra_category_id');
});

Schema::table('ifra_certificates', function (Blueprint $table): void {
    $table->foreignId('ifra_amendment_id')->nullable()->constrained()->nullOnDelete();
    $table->string('source_amendment_label')->nullable();
    $table->index('ifra_amendment_id');
});

DB::table('ifra_certificates')
    ->whereNotNull('ifra_amendment')
    ->orderBy('id')
    ->eachById(function (object $certificate): void {
        DB::table('ifra_certificates')
            ->where('id', $certificate->id)
            ->update(['source_amendment_label' => $certificate->ifra_amendment]);
    });
```

Do not add another index for `recipe_versions.ifra_product_category_id`; the existing `2026_08_11_095606_add_catalog_foreign_key_indexes.php` migration already provides it. Do not index `is_active`, `is_default`, `sort_order`, amendment status, or creation track alone; those fields are low-selectivity and the small reference tables are already covered by their primary, unique, and partial indexes.

Every `down()` must drop indexes, FKs, columns, and tables in reverse dependency order. Before restoring the non-null legacy family column, backfill each null `product_family_id` from that type's lowest-ID compatibility pivot row:

```php
DB::table('product_types')
    ->whereNull('product_family_id')
    ->orderBy('id')
    ->eachById(function (object $productType): void {
        $productFamilyId = DB::table('product_family_product_type')
            ->where('product_type_id', $productType->id)
            ->orderBy('product_family_id')
            ->value('product_family_id');

        if ($productFamilyId === null) {
            throw new LogicException("Product Type {$productType->id} has no legacy family for rollback.");
        }

        DB::table('product_types')
            ->where('id', $productType->id)
            ->update(['product_family_id' => $productFamilyId]);
    });
```

Before restoring the old composite unique index, preflight same-family slug collisions and report them explicitly:

```php
$legacySlugCollisions = DB::table('product_types')
    ->whereNotNull('product_family_id')
    ->get(['product_family_id', 'slug'])
    ->groupBy(fn (object $productType): string => $productType->product_family_id.':'.$productType->slug)
    ->filter(fn (Collection $productTypes): bool => $productTypes->count() > 1)
    ->keys()
    ->values();

if ($legacySlugCollisions->isNotEmpty()) {
    throw new LogicException(
        'Resolve legacy family/slug collisions before rolling back: '.$legacySlugCollisions->implode(', '),
    );
}
```

Complete `000001` rollback with Laravel Schema Builder in dependency-safe order:

```php
Schema::table('product_types', function (Blueprint $table): void {
    $table->dropIndex('product_types_active_slug_unique');
    $table->dropIndex(['product_category_id']);
    $table->foreignId('product_family_id')->nullable(false)->change();
    $table->unique(['product_family_id', 'slug']);
    $table->dropConstrainedForeignId('product_category_id');
});

Schema::table('recipes', function (Blueprint $table): void {
    $table->dropIndex(['product_type_id']);
});

Schema::dropIfExists('product_family_product_type');
Schema::dropIfExists('product_categories');
Schema::dropIfExists('product_areas');
```

Dropping `product_family_product_type` removes its reverse `product_type_id` index and composite primary-key index with the table; do not attempt to drop those separately.

`000003` must drop its added foreign keys before their referenced tables can be removed:

```php
Schema::table('recipe_versions', function (Blueprint $table): void {
    $table->dropIndex(['product_type_ifra_category_id']);
    $table->dropIndex(['ifra_amendment_id']);
    $table->dropConstrainedForeignId('product_type_ifra_category_id');
    $table->dropConstrainedForeignId('ifra_amendment_id');
    $table->dropColumn('ifra_category_selection_mode');
});

Schema::table('ifra_certificates', function (Blueprint $table): void {
    $table->dropIndex(['ifra_amendment_id']);
    $table->dropConstrainedForeignId('ifra_amendment_id');
    $table->dropColumn('source_amendment_label');
});
```

`000002` then drops `product_type_ifra_categories`, `ifra_amendment_milestones`, and `ifra_amendments` in that order. Dropping `product_type_ifra_categories` also removes its partial index. Retain the existing `ifra_certificates.ifra_amendment` string throughout this safe rollout.

- [ ] **Step 6: Run the focused test**

Run:

```bash
php artisan test --compact tests/Feature/ProductTaxonomyFoundationTest.php
```

Expected: PASS on SQLite, including the partial unique-index test.

- [ ] **Step 7: Commit the schema foundation**

```bash
git add app/Enums database/migrations tests/Feature/ProductTaxonomyFoundationTest.php
git commit -m "feat: add product taxonomy and IFRA amendment schema"
```

---

### Task 2: Add model relationships and factories

**Files:**
- Create: the five new models and five factories in the file map
- Modify: `app/Models/ProductFamily.php`
- Modify: `app/Models/ProductType.php`
- Modify: `app/Models/IfraProductCategory.php`
- Modify: `app/Models/IfraCertificate.php`
- Modify: `app/Models/RecipeVersion.php`
- Modify: `database/factories/ProductTypeFactory.php`
- Modify: `database/factories/IfraCertificateFactory.php`
- Test: `tests/Feature/ProductTaxonomyFoundationTest.php`

- [ ] **Step 1: Add failing relationship and enum-cast tests**

Test a category under an area, a type attached to both families, two amendment milestones, a type mapping, a certificate amendment, and a RecipeVersion whose selection mode casts to `IfraCategorySelectionMode::Automatic`.

- [ ] **Step 2: Run the focused test and verify it fails**

```bash
php artisan test --compact tests/Feature/ProductTaxonomyFoundationTest.php
```

Expected: FAIL because models and relationships do not exist.

- [ ] **Step 3: Generate models and factories**

```bash
php artisan make:model ProductArea --factory --no-interaction
php artisan make:model ProductCategory --factory --no-interaction
php artisan make:model ProductTypeIfraCategory --factory --no-interaction
php artisan make:model IfraAmendment --factory --no-interaction
php artisan make:model IfraAmendmentMilestone --factory --no-interaction
```

- [ ] **Step 4: Implement the relation surface**

Use `#[Fillable([...])]`, `casts()` methods, explicit relation return types, and these relationship names:

```php
ProductArea::productCategories(): HasMany
ProductCategory::productArea(): BelongsTo
ProductCategory::productTypes(): HasMany
ProductType::productCategory(): BelongsTo
ProductType::ifraCategoryMappings(): HasMany
IfraProductCategory::productTypeMappings(): HasMany
IfraAmendment::milestones(): HasMany
IfraAmendment::productTypeMappings(): HasMany
ProductTypeIfraCategory::productType(): BelongsTo
ProductTypeIfraCategory::ifraAmendment(): BelongsTo
ProductTypeIfraCategory::ifraProductCategory(): BelongsTo
IfraAmendmentMilestone::ifraAmendment(): BelongsTo
IfraCertificate::ifraAmendment(): BelongsTo
RecipeVersion::ifraAmendment(): BelongsTo
RecipeVersion::productTypeIfraCategory(): BelongsTo
```

Define both compatibility relations with Laravel's conventional inferred pivot and key names:

```php
public function productFamilies(): BelongsToMany
{
    return $this->belongsToMany(ProductFamily::class)->withTimestamps();
}

public function productTypes(): BelongsToMany
{
    return $this->belongsToMany(ProductType::class)->withTimestamps();
}
```

Laravel infers `product_family_product_type`, `product_family_id`, and `product_type_id` from these model names. Do not pass redundant custom table or key arguments. Audit all callers of `ProductFamily::productTypes()` because the relation changes from `HasMany` to `BelongsToMany` while retaining its public method name.

`ProductType` must no longer expose `productFamily()` or `defaultIfraProductCategory()` to new code. Keep the legacy fillable attributes only until Task 9 proves there are no runtime reads.

- [ ] **Step 5: Implement factory defaults**

Factories must build test-owned records, for example:

```php
return [
    'product_category_id' => ProductCategory::factory(),
    'product_family_id' => ProductFamily::factory(), // legacy rollout column only
    'default_ifra_product_category_id' => null,       // legacy rollout column only
    'name' => Str::title($name),
    'slug' => Str::slug($name),
    'sort_order' => fake()->numberBetween(1, 100),
    'is_active' => true,
    'description' => fake()->optional()->sentence(),
];
```

Add an `afterCreating()` callback to `ProductTypeFactory` that calls `syncWithoutDetaching([$productType->product_family_id])` only when the legacy ID is non-null. This makes the compatibility attachment idempotent when a test has already attached the same family explicitly.

- [ ] **Step 6: Run the focused tests**

```bash
php artisan test --compact tests/Feature/ProductTaxonomyFoundationTest.php tests/Feature/ProductTypeFoundationTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit models and factories**

```bash
git add app/Models database/factories tests/Feature/ProductTaxonomyFoundationTest.php tests/Feature/ProductTypeFoundationTest.php
git commit -m "feat: model product taxonomy and IFRA mappings"
```

---

### Task 3: Seed the canonical taxonomy and IFRA 51 mappings

**Files:**
- Create: `database/seeders/IfraAmendmentSeeder.php`
- Create: `database/seeders/ProductTaxonomySeeder.php`
- Create: `database/seeders/ProductTypeIfraCategorySeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Modify: `database/seeders/ProductTypeSeeder.php`
- Test: `tests/Feature/ProductTaxonomyFoundationTest.php`

- [ ] **Step 1: Generate the seeders with Artisan**

```bash
php artisan make:seeder IfraAmendmentSeeder --no-interaction
php artisan make:seeder ProductTaxonomySeeder --no-interaction
php artisan make:seeder ProductTypeIfraCategorySeeder --no-interaction
```

Expected: three seeder classes in `database/seeders` using the application's standard namespace and class structure.

- [ ] **Step 2: Write failing exact-catalog tests**

Seed the three new seeders and assert:

```php
expect(ProductArea::query()->pluck('slug')->all())
    ->toBe(['personal-care', 'home-household'])
    ->and(ProductType::query()->where('is_active', true)->count())->toBe(45)
    ->and(ProductType::query()->where('slug', 'bar-soap-cleansing-bar')->firstOrFail()->productFamilies()->pluck('slug')->sort()->values()->all())
    ->toBe(['cosmetic', 'soap'])
    ->and(ProductType::query()->where('slug', 'candle-wax-melt')->firstOrFail()->productFamilies()->pluck('slug')->all())
    ->toBe(['cosmetic']);
```

Assert all mapping groups have exactly one default except `other-cosmetics` and `other-home-product`, which have no mapping rows. Assert these load-bearing rows: shampoo 9, rinse-off conditioner 9, hair chemical treatment 7A, body mist default 2 plus alternative 4, candle 12, massage candle 5A, hand dishwashing 10A, hand-wash laundry 10A, machine dishwasher 12.

- [ ] **Step 3: Run and verify failure**

```bash
php artisan test --compact tests/Feature/ProductTaxonomyFoundationTest.php
```

Expected: FAIL because the reference catalog does not exist.

- [ ] **Step 4: Seed the current notified amendment and its milestones**

`IfraAmendmentSeeder` must use `updateOrCreate()` for amendment `51` with status `notified`, notification date `2023-06-30`, and the official Notification Letter URL. Seed these four exact milestones:

```php
[
    ['standard_kind' => 'prohibition', 'creation_track' => 'new', 'effective_on' => '2023-08-30'],
    ['standard_kind' => 'prohibition', 'creation_track' => 'existing', 'effective_on' => '2024-07-30'],
    ['standard_kind' => 'restriction_specification', 'creation_track' => 'new', 'effective_on' => '2024-03-30'],
    ['standard_kind' => 'restriction_specification', 'creation_track' => 'existing', 'effective_on' => '2025-10-30'],
]
```

Do not create a row for Amendment 52.

- [ ] **Step 5: Replace the form-based ProductType seed with the canonical matrix**

`ProductTaxonomySeeder` must encode every row in the Canonical user-facing taxonomy table above. For canonical rows other than `lip-product`, use `updateOrCreate(['slug' => $slug, 'is_active' => true], [...])`; this updates the active canonical row without reviving or overwriting an inactive historical duplicate. Synchronize category and family relationships with `sync()`, and leave existing unknown or legacy product types untouched but deactivate only the following nine superseded starter slugs:

```php
[
    'cream-lotion', 'balm-salve', 'deodorant', 'hair-care', 'mask',
    'oil-blend-serum', 'cleansing-non-saponified', 'bath-salts-soaks', 'other',
]
```

Resolve `lip-product` across active and inactive rows before the normal loop. Require at most one matching historical row, reactivate and move that exact row into `Lips & oral care`, and preserve its ID; throw a `LogicException` listing matching IDs if private data contains more than one. This protects existing foreign keys without making the seeder choose an arbitrary inactive duplicate.

- [ ] **Step 6: Seed amendment-scoped IFRA mappings**

`ProductTypeIfraCategorySeeder` must resolve categories by code and create one mapping per default in the matrix. Add the body-mist alternative with this exact guidance:

```text
Use Category 4 only when the product is clearly labelled not for axillary use and not as a deodorant; otherwise foreseeable axillary use keeps it in Category 2.
```

Add guidance to the two use-derived mappings that are not literal product rows in the IFRA table:

```text
Skin-contact massage candle, Category 5A: use this mapping only when the melted product is intended to be applied to the body as a leave-on massage or body oil. An ordinary burned candle or wax melt is Category 12.

Aftershave cream or balm, Category 5B: use this mapping when the product is presented and used as a leave-on face moisturizer. Aftershaves other than creams and balms are Category 4.
```

Store the official 51st Guidance URL in `source_url` on every seeded mapping. Cover both the mapping code and the exact interpretive guidance in the catalog test so a future maintainer cannot silently recategorize them.

All default mappings use `is_default=true`, `sort_order=10`; the body-mist Category 4 alternative uses `is_default=false`, `sort_order=20`. Validate after seeding that every non-empty `(product_type_id, amendment_id)` group contains exactly one default; throw `LogicException` with the product type slug and amendment code if not.

- [ ] **Step 7: Correct seeder ordering**

In `DatabaseSeeder`, order the relevant calls as:

```php
ProductFamilySeeder::class,
IfraProductCategorySeeder::class,
IfraAmendmentSeeder::class,
ProductTaxonomySeeder::class,
ProductTypeIfraCategorySeeder::class,
```

Reduce `ProductTypeSeeder` to a compatibility wrapper that calls `ProductTaxonomySeeder`, or remove it from `DatabaseSeeder` after updating every direct test reference.

- [ ] **Step 8: Run exact seed tests twice to prove idempotence**

```bash
php artisan test --compact tests/Feature/ProductTaxonomyFoundationTest.php
```

Expected: PASS, including a test that calls all three seeders twice without duplicate rows.

- [ ] **Step 9: Commit reference data**

```bash
git add database/seeders tests/Feature/ProductTaxonomyFoundationTest.php
git commit -m "feat: seed finished-product taxonomy and IFRA 51 mappings"
```

---

### Task 4: Enforce Product classification compatibility and immutability

**Files:**
- Create: `app/Services/ProductClassificationService.php`
- Modify: `app/Services/RecipeWorkbenchContextResolver.php`
- Modify: `app/Services/RecipeWorkbenchViewDataBuilder.php`
- Modify: `app/Services/RecipeWorkbenchPayloadNormalizer.php`
- Modify: `app/Services/RecipeVersionRecordService.php`
- Test: `tests/Feature/ProductClassificationServiceTest.php`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`
- Test: `tests/Feature/CosmeticRecipeWorkbenchTest.php`

- [ ] **Step 1: Generate the service and Pest test with Artisan**

```bash
php artisan make:class Services/ProductClassificationService --no-interaction
php artisan make:test --pest ProductClassificationServiceTest --no-interaction
```

Expected: the service is created under `app/Services` and the feature test under `tests/Feature`.

- [ ] **Step 2: Write failing compatibility and lifecycle tests**

Cover these cases:

- a dual-engine cleansing bar saves through both families;
- a candle cannot save through the soap family;
- a soap-family payload keeps its product type instead of the current hard-coded `null`;
- product type may change while only a Current formula exists;
- the first Saved Formula may use the final selected type;
- after a Saved Formula exists, a different type raises `ValidationException` on `product_type_id`;
- changing `product_family_id` for an existing Product is rejected;
- the unchanged inactive legacy type still reloads and saves.
- an existing legacy Product with both stored and submitted type null remains unclassified and saves without being guessed;
- both draft creation and direct publish creation pass the resolved type to `createRecipe()`;
- neither the draft nor publisher path can change the type after the first Saved Formula.

- [ ] **Step 3: Run tests and verify the current contradiction**

```bash
php artisan test --compact tests/Feature/ProductClassificationServiceTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
```

Expected: FAIL because soap normalization nulls the type, compatibility is family-owned, and `fillVersion()` currently changes the Product type on every save.

- [ ] **Step 4: Implement the classification module**

Give the module this small interface:

```php
public function resolveForSave(ProductFamily $family, ?int $productTypeId, ?Recipe $product): ?ProductType;
```

Its implementation must:

1. require a Product Type for new Products;
2. return null only when an existing legacy Product has no stored type and the submitted type is also null;
3. reject clearing a Product that already has a type;
4. load a submitted type with `productFamilies`;
5. allow the Product's existing inactive type;
6. reject a type not attached to the requested family;
7. reject a family different from the existing Product's family;
8. query `latestPublishedVersion()->exists()` and reject a changed type only after the first Saved Formula.

Use `ValidationException::withMessages()` and the existing `product_type_id` payload key.

- [ ] **Step 5: Route all persistence through the module**

Inject `ProductClassificationService` into `RecipeWorkbenchPayloadNormalizer`. Replace the soap branch's:

```php
'product_type_id' => null,
```

with the resolved type ID, and use the same resolver in the cosmetic branch. Pass the current Product into normalization from `RecipeWorkbenchService`.

In `RecipeVersionRecordService::fillVersion()`, remove the unconditional type mutation. Set `product_type_id` during `createRecipe()`, and only update it when the classification module has already established that no Saved Formula exists.

`RecipeDraftSaver` and `RecipeVersionPublisher` are creation call sites, not independent mutation authorities. Keep both passing the normalized, already-resolved `product_type_id` into `createRecipe()` and prove their behavior through the lifecycle tests above; do not add a second classification decision inside either service.

- [ ] **Step 6: Make route resolution use the many-to-many compatibility relation**

Replace `whereBelongsTo($productFamily)` in both `RecipeWorkbenchContextResolver::productType()` and `RecipeWorkbenchViewDataBuilder::productTypes()` with the many-to-many compatibility constraint. The single-record resolver becomes:

```php
return ProductType::query()
    ->whereHas('productFamilies', fn (Builder $query): Builder => $query->whereKey($productFamily->id))
    ->where('slug', $slug)
    ->where('is_active', true)
    ->firstOrFail();
```

The list builder must use the same `whereHas('productFamilies', ...)` condition before its active and ordering clauses, while still appending the selected inactive legacy type for round-trip. Run `rg -n "whereBelongsTo\(|->productTypes" app tests` and update every remaining caller that assumes `ProductFamily::productTypes()` is `HasMany`.

- [ ] **Step 7: Run focused lifecycle tests**

```bash
php artisan test --compact tests/Feature/ProductClassificationServiceTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
```

Expected: PASS.

- [ ] **Step 8: Commit classification enforcement**

```bash
git add app/Services tests/Feature/ProductClassificationServiceTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
git commit -m "feat: enforce stable product classification"
```

---

### Task 5: Resolve and persist optional IFRA suggestions

**Files:**
- Rename: `app/Services/RecipeWorkbenchIfraOptionsBuilder.php` to `app/Services/ProductTypeIfraOptionsBuilder.php`
- Modify: `app/Services/RecipeWorkbenchViewDataBuilder.php`
- Modify: `app/Services/RecipeWorkbenchPayloadNormalizer.php`
- Modify: `app/Services/RecipeVersionRecordService.php`
- Modify: `app/Services/RecipeWorkbenchDraftPayloadMapper.php`
- Modify: `app/Services/RecipeWorkbenchVersionPayloadMapper.php`
- Modify: `app/Models/RecipeVersion.php`
- Test: `tests/Feature/ProductTypeIfraOptionsBuilderTest.php`
- Test: `tests/Feature/RecipeWorkbenchViewDataBuilderTest.php`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`

- [ ] **Step 1: Generate the new Pest test with Artisan**

```bash
php artisan make:test --pest ProductTypeIfraOptionsBuilderTest --no-interaction
```

Expected: a feature test at `tests/Feature/ProductTypeIfraOptionsBuilderTest.php`.

- [ ] **Step 2: Write failing suggestion tests**

Assert:

- the latest notified amendment is selected by `notification_date`, not an `is_latest` flag;
- consultation rows are ignored;
- when a newer notified amendment has no mapping for the selected Product Type, the latest older notified amendment with active mappings for that type is used;
- an unmapped Other type still reports the globally latest notified amendment while returning no suggested options;
- shampoo and rinse-off conditioner automatically resolve Category 9;
- body mist returns Category 2 first and Category 4 with guidance second;
- `Other cosmetics` returns no default, no mapped candidates, and every active IFRA category in `all_categories`;
- an automatic null payload resolves the mapped default;
- a manual null payload stays null;
- a manual mapped alternative records its mapping ID;
- a manual category outside the candidate list is allowed, records the category, and leaves mapping ID null;
- saving works with no aromatic ingredient and with no IFRA category.

- [ ] **Step 3: Run and verify failure**

```bash
php artisan test --compact tests/Feature/ProductTypeIfraOptionsBuilderTest.php tests/Feature/RecipeWorkbenchViewDataBuilderTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
```

Expected: FAIL because options are still family-scoped and selection mode/amendment evidence are not persisted.

- [ ] **Step 4: Implement the deep options module**

Rename the existing builder and implement the `build(?ProductType $productType, ?IfraProductCategory $selected = null): array` interface defined in the Target relation model. Resolve the globally latest notified amendment first, then prefer the latest notified amendment containing an active mapping for the selected Product Type:

```php
$notifiedAmendments = IfraAmendment::query()
    ->where('status', IfraAmendmentStatus::Notified)
    ->whereNotNull('notification_date')
    ->whereDate('notification_date', '<=', today())
    ->orderByDesc('notification_date')
    ->orderByDesc('id');

$latestNotifiedAmendment = (clone $notifiedAmendments)->first();

$mappedAmendment = $productType instanceof ProductType
    ? (clone $notifiedAmendments)
        ->whereHas(
            'productTypeMappings',
            fn (Builder $query): Builder => $query
                ->where('product_type_id', $productType->id)
                ->where('is_active', true),
        )
        ->first()
    : null;

$amendment = $mappedAmendment ?? $latestNotifiedAmendment;
```

This deliberately keeps mapped products on Amendment 51 if Amendment 52 is notified before its Product Type mapping dataset ships, instead of silently returning empty suggestions. For an intentionally unmapped Other type, the fallback remains the globally latest notified amendment.

When a Product Type has mappings under the resolved amendment, return active mapped candidates in `options`. Always return the complete active category catalog in `all_categories`, plus the currently selected inactive category when necessary for round-trip. For an unmapped Other type, return an empty `options` list, the complete `all_categories` catalog, and no default. Return the selected amendment's milestones as descriptive reference data; never choose a fragrance creation track.

- [ ] **Step 5: Normalize automatic versus manual mode on the backend**

Add these normalized keys:

```php
'ifra_category_selection_mode' => IfraCategorySelectionMode::tryFrom(
    (string) ($payload['ifra_category_selection_mode'] ?? 'automatic')
) ?? IfraCategorySelectionMode::Automatic,
'ifra_amendment_id' => $resolvedAmendment?->id,
'product_type_ifra_category_id' => $resolvedMapping?->id,
'ifra_product_category_id' => $resolvedCategory?->id,
```

Automatic mode ignores a stale client category and resolves the current product-type default. Manual mode accepts a selected active category or explicit null. Neither mode may throw simply because no category or no mapping exists.

- [ ] **Step 6: Persist the resolution evidence**

In `RecipeVersionRecordService::fillVersion()` assign all four IFRA fields. Add enum casting in `RecipeVersion::casts()`.

Update both payload mappers so the browser receives and round-trips:

```php
'ifraCategorySelectionMode' => $version->ifra_category_selection_mode?->value ?? 'automatic',
'ifraAmendmentId' => $version->ifra_amendment_id,
'selectedIfraProductCategoryId' => $version->ifra_product_category_id,
```

Do not expose or persist a new/existing fragrance creation track.

- [ ] **Step 7: Update Workbench view data**

Replace family-based builder calls with one call using the resolved Product Type. Return:

```php
'ifraGuidance' => $ifraOptions,
'ifraProductCategories' => $ifraOptions['all_categories'],
'defaultIfraProductCategoryId' => $ifraOptions['default_category_id'],
```

Delete the special case that intentionally returns `null` for all new cosmetics.

- [ ] **Step 8: Run focused tests**

```bash
php artisan test --compact tests/Feature/ProductTypeIfraOptionsBuilderTest.php tests/Feature/RecipeWorkbenchViewDataBuilderTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
```

Expected: PASS.

- [ ] **Step 9: Commit amendment-aware suggestions**

```bash
git add app/Models/RecipeVersion.php app/Services tests/Feature
git commit -m "feat: resolve optional IFRA guidance by product type"
```

---

### Task 6: Build the three-entry Product creation flow

**Files:**
- Create: `app/Services/ProductCreationCatalog.php`
- Create: `resources/views/recipes/create-start.blade.php`
- Replace: `resources/views/recipes/product-type-selector.blade.php`
- Modify: `app/Http/Controllers/RecipeController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/livewire/dashboard/recipes-index.blade.php`
- Modify: `resources/views/dashboard.blade.php`
- Modify: `lang/en/products.php`
- Test: `tests/Feature/ProductCreationFlowTest.php`
- Test: `tests/Feature/CosmeticRecipeWorkbenchTest.php`

- [ ] **Step 1: Generate the service, view, and Pest test with Artisan**

```bash
php artisan make:class Services/ProductCreationCatalog --no-interaction
php artisan make:view recipes.create-start --no-interaction
php artisan make:test --pest ProductCreationFlowTest --no-interaction
```

Expected: the service, Blade view, and feature test are created in their conventional application directories.

- [ ] **Step 2: Write failing route and grouping tests**

Assert:

- `recipes.start` shows exactly Soap, Cosmetics, Home;
- Soap shows compatible types grouped under Personal care and Home & household;
- Cosmetics shows only Personal care types compatible with `cosmetic`;
- Home shows only Home & household types compatible with `cosmetic`;
- selecting a type opens the existing `recipes.create` Workbench route with family and type;
- `/dashboard/recipes/new?family=cosmetic` remains a valid backwards-compatible direct Workbench URL only when a type is also present;
- a missing type redirects to the appropriate selector rather than opening an unclassified formula;
- inactive types and inactive categories are absent.

- [ ] **Step 3: Run and verify failure**

```bash
php artisan test --compact tests/Feature/ProductCreationFlowTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
```

Expected: FAIL because there is no start page and the existing selector is unused and cosmetic-only.

- [ ] **Step 4: Implement the creation catalog**

Expose two methods:

```php
public function entries(): array;
public function groupedTypes(string $entry): array;
```

Use this exact entry configuration internally:

```php
[
    'soap' => ['family' => 'soap', 'area' => null],
    'cosmetics' => ['family' => 'cosmetic', 'area' => 'personal-care'],
    'home' => ['family' => 'cosmetic', 'area' => 'home-household'],
]
```

`groupedTypes()` loads active areas, categories, types, and family compatibility in sort order and returns plain arrays for the view.

- [ ] **Step 5: Add class-based routes and controller methods**

Inside the existing Recipe controller group add, before `/{recipe}` routes:

```php
Route::get('/start', 'start')->name('start');
Route::get('/start/{entry}', 'chooseProductType')->name('choose-type');
```

Keep the existing `Route::get('/new', 'create')->name('create')` exactly once; do not re-register it. Place both new `/start` routes before all dynamic `/{recipe}` routes so `start` cannot be captured as a recipe route parameter.

`start()` renders `recipes.create-start`; `chooseProductType()` validates the entry through `ProductCreationCatalog`; the existing `create()` method redirects to `recipes.choose-type` when family/type is incomplete and otherwise retains the existing Workbench behavior.

- [ ] **Step 6: Build concise views**

`create-start.blade.php` uses three cards with this copy:

- Soap — “Oils + lye”
- Cosmetics — “Skin, hair, melt-and-pour and syndets”
- Home — “Candles, cleaning and laundry”

The selector groups cards by Area then Category. Each card shows only the Product Type name and one short description; do not show IFRA, formula engine, FDA, EU, or “general formula”.

- [ ] **Step 7: Route every New Product button through the start page**

Replace the separate “New soap” / “New cosmetic” buttons in the recipe index and dashboard with one `New product` action to `recipes.start`. Preserve the direct Workbench route for selected-type links and old bookmarks.

- [ ] **Step 8: Run creation-flow tests**

```bash
php artisan test --compact tests/Feature/ProductCreationFlowTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php tests/Feature/RecipesIndexTest.php
```

Expected: PASS.

- [ ] **Step 9: Commit the creation flow**

```bash
git add app/Http/Controllers/RecipeController.php app/Services/ProductCreationCatalog.php routes/web.php resources/views lang/en/products.php tests/Feature
git commit -m "feat: add Soap Cosmetics and Home creation flow"
```

---

### Task 7: Make IFRA unobtrusive and editable in the Workbench

**Files:**
- Create: `resources/views/livewire/dashboard/partials/recipe-workbench/ifra-category-modal.blade.php`
- Modify: `resources/views/livewire/dashboard/partials/recipe-workbench/formula-settings.blade.php`
- Modify: `resources/js/recipe-workbench/component.js`
- Modify: `resources/js/recipe-workbench/payload.js`
- Modify: `resources/js/recipe-workbench/snapshot.js`
- Modify: `lang/en/workbench.php`
- Test: `tests/Feature/RecipeWorkbenchContractTest.php`
- Test: `tests/Feature/RecipeWorkbenchDesignPolishTest.php`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`

- [ ] **Step 1: Generate the modal view with Artisan**

```bash
php artisan make:view livewire.dashboard.partials.recipe-workbench.ifra-category-modal --no-interaction
```

Expected: `resources/views/livewire/dashboard/partials/recipe-workbench/ifra-category-modal.blade.php` exists.

- [ ] **Step 2: Write failing UI-contract and round-trip tests**

Assert the Workbench:

- starts with compliance collapsed;
- does not show IFRA in the creation selector or main formula heading;
- shows “Suggested from product type” inside compliance for automatic mode;
- opens an accessible modal with mapped candidates and guidance;
- offers “Choose another IFRA category” to reveal the complete active list;
- offers “No IFRA category” and switches to manual null;
- offers “Use suggested category” to return to automatic mode;
- disables Product Type changes after `has_saved_formula=true`;
- sends and hydrates `ifra_category_selection_mode` without changing existing dirty-state behavior.

- [ ] **Step 3: Run and verify failure**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchContractTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php
```

Expected: FAIL because the current compliance panel contains a raw all-category combobox and has no selection mode.

- [ ] **Step 4: Add browser state and payload fields**

Initialize:

```js
ifraCategorySelectionMode: payload.savedDraft?.ifraCategorySelectionMode ?? 'automatic',
ifraGuidance: payload.ifraGuidance ?? { amendment: null, default_category_id: null, options: [] },
isIfraCategoryModalOpen: false,
showAllIfraCategories: false,
```

Add methods that set both ID and mode:

```js
useSuggestedIfraCategory() {
    this.ifraCategorySelectionMode = 'automatic';
    this.selectedIfraProductCategoryId = this.ifraGuidance.default_category_id === null
        ? ''
        : String(this.ifraGuidance.default_category_id);
}

selectIfraCategory(categoryId) {
    this.ifraCategorySelectionMode = 'manual';
    this.selectedIfraProductCategoryId = String(categoryId);
    this.isIfraCategoryModalOpen = false;
}

clearIfraCategory() {
    this.ifraCategorySelectionMode = 'manual';
    this.selectedIfraProductCategoryId = '';
    this.isIfraCategoryModalOpen = false;
}
```

Include `ifra_category_selection_mode` in `payload.js`, `snapshot.js`, and the existing dirty-key list.

- [ ] **Step 5: Replace both duplicate IFRA comboboxes with one included modal**

Keep the compliance disclosure collapsed. Its summary shows the resolved badge only inside the disclosure. Include the same partial in the soap and cosmetic branches:

```blade
@include('livewire.dashboard.partials.recipe-workbench.ifra-category-modal')
```

The modal lists mapped candidates first, displays each mapping's `guidance`, and only lists every active category after the user asks for another category. Add this concise disclaimer:

```text
Optional guidance. If you market one product for several uses, review every applicable IFRA category; Koskalk does not choose a universal “strictest” category.
```

If the selected notified amendment has a future milestone, show a collapsed “IFRA amendment timing” note listing both fragrance-mixture tracks. Its wording must say the dates apply to fragrance mixtures leaving a fragrance house and that Koskalk cannot infer whether the supplier's fragrance is a new or existing creation. This notice is informational and must never block saving.

- [ ] **Step 6: Lock the Product Type control after the first Saved Formula**

Use the existing `recipe.has_saved_formula` view data to disable selection and show:

```text
Product type is fixed after the first Saved Formula. Duplicate the Product to classify a different finished use.
```

The backend validation from Task 4 remains authoritative.

- [ ] **Step 7: Run UI and persistence tests**

```bash
php artisan test --compact tests/Feature/RecipeWorkbenchContractTest.php tests/Feature/RecipeWorkbenchDesignPolishTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/CosmeticRecipeWorkbenchTest.php
```

Expected: PASS.

- [ ] **Step 8: Build frontend assets**

```bash
npm run build
```

Expected: Vite exits successfully with no import or syntax error.

- [ ] **Step 9: Commit Workbench UX**

```bash
git add resources/js/recipe-workbench resources/views/livewire/dashboard/partials/recipe-workbench lang/en/workbench.php tests/Feature
git commit -m "feat: add optional IFRA suggestion modal"
```

---

### Task 8: Replace calculation-family filters with product taxonomy filters

**Files:**
- Modify: `app/Livewire/Dashboard/RecipesIndex.php`
- Modify: `resources/views/livewire/dashboard/recipes-index.blade.php`
- Modify: `lang/en/products.php`
- Modify: `tests/Feature/RecipesIndexTest.php`
- Modify: `tests/Feature/RecipesIndexLocalizationTest.php`

- [ ] **Step 1: Write failing area/category/type filter tests**

Create Products across both areas and assert filters cascade Area → Category → Product Type. Assert the card label is the Product Type, not `Soap` or `Cosmetic`, and that searching matches area/category/type names.

- [ ] **Step 2: Run and verify failure**

```bash
php artisan test --compact tests/Feature/RecipesIndexTest.php tests/Feature/RecipesIndexLocalizationTest.php
```

Expected: FAIL because the page currently exposes `productFamilyFilter` as “category”.

- [ ] **Step 3: Replace public filter state**

Use URL-bound state:

```php
#[Url(as: 'area')]
public string $productAreaFilter = '';

#[Url(as: 'category')]
public string $productCategoryFilter = '';

#[Url(as: 'type')]
public string $productTypeFilter = '';
```

Eager-load `productType.productCategory.productArea`. Build options only from the current workspace's Products, reset children when a parent changes, and query through `whereHas()` relationships. Do not expose calculation family as a primary user filter.

- [ ] **Step 4: Update the Blade filters and card fallback**

Render Area, Category, Type, Status. Use Product Type name as the card category label; only fall back to a localized “Unclassified product” for legacy records.

- [ ] **Step 5: Run index tests**

```bash
php artisan test --compact tests/Feature/RecipesIndexTest.php tests/Feature/RecipesIndexLocalizationTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit taxonomy navigation**

```bash
git add app/Livewire/Dashboard/RecipesIndex.php resources/views/livewire/dashboard/recipes-index.blade.php lang/en/products.php tests/Feature/RecipesIndexTest.php tests/Feature/RecipesIndexLocalizationTest.php
git commit -m "feat: filter Products by finished-product taxonomy"
```

---

### Task 9: Update admin reference-data surfaces and certificate amendment links

**Files:**
- Modify: `app/Filament/Resources/ProductTypes/Schemas/ProductTypeForm.php`
- Modify: `app/Filament/Resources/ProductTypes/Tables/ProductTypesTable.php`
- Modify: `app/Filament/Resources/IfraCertificates/Schemas/IfraCertificateForm.php`
- Modify: `app/Filament/Resources/IfraCertificates/Tables/IfraCertificatesTable.php`
- Modify: `app/Services/IngredientDataEntryService.php`
- Modify: `app/Livewire/Dashboard/IngredientEditor.php`
- Modify: `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php`
- Test: `tests/Feature/ProductTypeFoundationTest.php`
- Test: focused existing ingredient data-entry and IFRA certificate tests found with `rg -n "ifra_amendment|IfraCertificate" tests`.

- [ ] **Step 1: Write failing admin form tests**

Assert Product Type admin edits Category and compatible Families, not one family/default column. Assert certificate forms select a known Amendment and restrict limit categories to active canonical categories. Assert the legacy source amendment label is still readable.

- [ ] **Step 2: Run and verify failure**

```bash
php artisan test --compact tests/Feature/ProductTypeFoundationTest.php
```

Expected: FAIL because the forms still bind legacy fields.

- [ ] **Step 3: Update Product Type admin**

Replace `product_family_id` with:

```php
Select::make('product_category_id')
    ->relationship('productCategory', 'name')
    ->searchable()
    ->preload()
    ->required(),
Select::make('productFamilies')
    ->relationship('productFamilies', 'name')
    ->multiple()
    ->preload()
    ->required(),
```

Remove `default_ifra_product_category_id`. Display Area, Category, Families, active mapping count, and Product count in the table. IFRA defaults are amendment-scoped reference data seeded and validated separately in this release.

- [ ] **Step 4: Update certificate amendment input**

Replace free-text `ifra_amendment` with:

```php
Select::make('ifra_amendment_id')
    ->label('IFRA amendment')
    ->relationship('ifraAmendment', 'code')
    ->searchable()
    ->preload()
    ->nullable()
    ->helperText('Optional when the source document does not identify an amendment.'),
```

Show `source_amendment_label` read-only when it exists and does not match the linked amendment. Update ingredient intake/editor serialization to use the FK while preserving the raw label.

- [ ] **Step 5: Run Filament and ingredient tests**

Run the focused tests discovered in Step 1, then:

```bash
vendor/bin/filacheck --fix
```

Expected: tests PASS and Filacheck reports no remaining issue in modified Filament files.

- [ ] **Step 6: Commit admin updates**

```bash
git add app/Filament app/Livewire/Dashboard/IngredientEditor.php app/Services/IngredientDataEntryService.php tests
git commit -m "feat: manage taxonomy and IFRA amendments in admin"
```

---

### Task 10: Backfill deployed Products and prove legacy runtime paths are unused

**Files:**
- Modify: `database/migrations/2026_08_20_000001_expand_product_taxonomy.php`
- Modify: `database/migrations/2026_08_20_000003_add_ifra_resolution_to_recipe_versions_and_certificates.php`
- Modify: `app/Models/ProductFamily.php`
- Modify: `app/Models/ProductType.php`
- Modify: `app/Models/IfraProductCategory.php`
- Test: `tests/Feature/ProductTaxonomyFoundationTest.php`
- Test: `tests/Feature/RecipeWorkbenchPersistenceTest.php`

- [ ] **Step 1: Add failing legacy-data migration tests**

Before running the new seeders, create:

- an existing Product with null type and a Saved Formula;
- a Product using one of the old starter Product Types;
- a legacy family-level IFRA mapping;
- a certificate with `ifra_amendment='51'`.

After migrate/seed behavior, assert:

- no existing Product loses its family, versions, or formula items;
- null legacy type remains loadable as “Unclassified product” rather than being guessed as body soap;
- the old typed Product keeps its exact Product Type ID, now inactive if superseded;
- the certificate links to Amendment 51 and preserves its raw label;
- no Workbench builder or persistence service queries `product_family_ifra_categories`, `product_types.default_ifra_product_category_id`, or `ProductType::productFamily()`.

- [ ] **Step 2: Implement conservative backfills**

Do not guess intended use for existing Products. Keep `recipes.product_type_id` nullable for legacy rows; require it only for new saves through `ProductClassificationService`. Backfill certificate amendment FK where trimmed legacy text exactly matches an amendment code. Mark pre-existing RecipeVersion rows with an IFRA category as `legacy`; leave null rows `automatic`.

- [ ] **Step 3: Remove legacy relationships from runtime models**

Remove:

```php
ProductFamily::ifraCategoryMappings()
ProductFamily::ifraProductCategories()
IfraProductCategory::productFamilyMappings()
IfraProductCategory::productFamilies()
ProductType::productFamily()
ProductType::defaultIfraProductCategory()
```

Keep the legacy model/table/files present but unused for this safe deployment. Add an architecture assertion that `app/Services` and `app/Livewire` do not depend on `ProductFamilyIfraCategory`.

- [ ] **Step 4: Run migration and persistence tests**

```bash
php artisan test --compact tests/Feature/ProductTaxonomyFoundationTest.php tests/Feature/ProductTypeFoundationTest.php tests/Feature/RecipeWorkbenchPersistenceTest.php tests/Feature/RecipeWorkbenchViewDataBuilderTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit safe backfill and deprecation**

```bash
git add app/Models database/migrations tests/Feature
git commit -m "refactor: retire family-level IFRA classification"
```

---

### Task 11: Full verification and durable project rules

**Files:**
- Modify through Laravel Boost `record-rule`: `.ai/rules/models-services.md`
- Modify through Laravel Boost `record-rule`: `.ai/rules/app.md`

- [ ] **Step 1: Run focused suites**

```bash
php artisan test --compact \
  tests/Feature/ProductTaxonomyFoundationTest.php \
  tests/Feature/ProductClassificationServiceTest.php \
  tests/Feature/ProductTypeIfraOptionsBuilderTest.php \
  tests/Feature/ProductCreationFlowTest.php \
  tests/Feature/ProductTypeFoundationTest.php \
  tests/Feature/RecipeWorkbenchViewDataBuilderTest.php \
  tests/Feature/RecipeWorkbenchPersistenceTest.php \
  tests/Feature/RecipeWorkbenchContractTest.php \
  tests/Feature/RecipeWorkbenchDesignPolishTest.php \
  tests/Feature/CosmeticRecipeWorkbenchTest.php \
  tests/Feature/RecipesIndexTest.php \
  tests/Feature/RecipesIndexLocalizationTest.php
```

Expected: PASS.

- [ ] **Step 2: Run the full suite**

```bash
php artisan test --compact
```

Expected: PASS with no regression.

- [ ] **Step 3: Format and validate Filament**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/filacheck --fix
```

Expected: both commands exit successfully; rerun affected tests if either changes files.

- [ ] **Step 4: Build frontend assets**

```bash
npm run build
```

Expected: successful production build.

- [ ] **Step 5: Refresh the repository graph**

```bash
graphify update .
```

Expected: `graphify-out/` refreshes successfully.

- [ ] **Step 6: Record the two load-bearing rules with Boost**

Record for `app/{Models,Services}/**`:

```text
Product type is a finished-product classification; Product family is a calculation engine. A Product Type may support multiple families. Product family is immutable after creation, and Product type is immutable after the first Saved Formula.
```

Record for `app/**`:

```text
IFRA classification is optional, non-blocking guidance. Suggest from amendment-scoped Product Type mappings, persist automatic/manual selection evidence on the Saved Formula, and never infer IFRA new/existing fragrance-creation status from a finished Product.
```

- [ ] **Step 7: Inspect the final diff**

```bash
git status --short
git diff --stat
git diff --check
```

Expected: only planned files and generated graph/rule changes; no whitespace errors.

- [ ] **Step 8: Commit final formatting, rules, and graph refresh**

```bash
git add .ai/rules graphify-out
git commit -m "docs: record product classification invariants"
```

## Self-review checklist

- Product/Recipe placement contradiction is resolved explicitly.
- Product family and type history behavior is explicit and server-enforced.
- The pure pivot is conventionally named `product_family_product_type`, has no artificial ID, and uses a composite primary key; both Eloquent relations rely on Laravel's inferred table and foreign-key names.
- New framework-owned artifacts are generated through Artisan rather than hand-scaffolded.
- `BelongsTo` method names match their foreign-key stems, including `ifraAmendment()` → `ifra_amendment_id`.
- Migrations use Schema Builder and the query builder, never application models; driver-specific raw SQL is limited to guarded partial indexes.
- Every added foreign key has explicit delete behavior and every migration reverses its work in dependency-safe order.
- Constraint-backed indexes are not duplicated; explicit indexes cover reverse pivot lookup, Product category navigation, Product Type filtering, and new IFRA foreign keys on high-volume or joined tables.
- Low-selectivity flags and sort columns are not indexed alone.
- Active Product Type slugs are globally unique without destroying inactive historical duplicates.
- Existing unclassified Products may remain null; every newly created Product requires a type.
- Soap noodles, melt-and-pour, syndets, saponified soap, household soap, candles, and massage candles are represented without using ingredients as categories.
- Shampoo and rinse-off conditioner map to IFRA 9; 7A remains hair chemical treatments/dyes.
- Laundry, dishwashing, fine fragrance, body mist, candle, reed diffuser, room spray, and pillow spray examples match notified IFRA 51 guidance.
- Amendment dates support standard kind plus new/existing fragrance-mixture track.
- No 52nd consultation data is treated as final.
- Amendment resolution falls back to the newest notified amendment that actually has mappings for the selected type.
- At most one mapping default is DB-enforced; exactly one for non-empty seed groups is catalog-validated.
- IFRA remains optional even with no aromatic material and even for Other types.
- Existing Product intent is never guessed during backfill.
- FDA/EU categories and multi-use finished products remain explicit non-goals rather than accidental omissions.
