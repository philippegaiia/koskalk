# Price Management and Ingredient Duplication

## Summary

Users can price any ingredient (platform or personal) in the costing tab, but there is no surface outside a recipe to view or edit those saved prices. Platform ingredients are invisible on the Ingredients page, so users cannot duplicate them to customize allergens, IFRA, or compliance data. Two dead columns (`price_eur`, `display_name_en`) add noise to the `ingredients` table.

This design adds a priced-ingredients section to the Ingredients page, a duplication flow for platform ingredients, and removes the dead columns.

## Goals

- Let users view and edit their saved ingredient prices without opening a recipe
- Let users duplicate any platform ingredient to own and customize it
- Remove unused columns from the ingredients table
- Preserve the existing costing flow — no changes to how prices prefilled or saved during costing

## Non-Goals

- No restricted component data model (separate iteration)
- No changes to the costing tab, packaging flow, or recipe workbench
- No cross-ingredient compliance accumulation
- No ingredient versioning system for user-owned copies

## Data Model Changes

### Remove Columns

Drop from `ingredients`:
- `price_eur` (decimal 10,2) — never used in costing; pricing lives in `user_ingredient_prices`
- `display_name_en` (varchar 255) — `display_name` is the canonical field; translations handled by Laravel's localization

Migration:
```php
Schema::table('ingredients', function (Blueprint $table) {
    $table->dropColumn('price_eur');
    $table->dropColumn('display_name_en');
});
```

Update `Ingredient` model:
- Remove `price_eur` and `display_name_en` from the `#[Fillable]` attribute
- Remove `price_eur` decimal cast (currently `'price_eur' => 'decimal:2'`)

Files that reference these columns and need cleanup:
- `app/Models/Ingredient.php` — fillable attribute + cast
- `app/Services/IngredientDataEntryService.php` — reads/writes both fields in `formData()` and `syncCurrentData()`
- `database/factories/IngredientFactory.php` — factory definition
- `database/seeders/IngredientCatalogSeeder.php` — seeder populates them from import data
- `app/Filament/Resources/Ingredients/Schemas/IngredientForm.php` — admin form has inputs for both
- `app/Filament/Exports/IngredientExporter.php` — exporter includes both columns
- `tests/Feature/IngredientDataEntryServiceTest.php` — test references
- `tests/Feature/CatalogSeederTest.php` — test references

### No New Tables

The duplication feature reuses the existing `ingredients` table. A duplicated ingredient is a normal user-owned ingredient record created with data copied from a platform ingredient.

## Price Management

### User Flow

1. User opens the Ingredients page (`/dashboard/ingredients`)
2. Below the existing "My ingredients" table, a second section appears: "Priced ingredients"
3. This section lists platform ingredients where the user has a `user_ingredient_prices` row
4. Each row shows: ingredient name, INCI, category badge, saved price/kg (editable), currency, last priced date
5. User edits the price inline → saves to `user_ingredient_prices` → updates `last_used_at`
6. Section only renders when the user has at least one priced ingredient

### Livewire Component Changes

`IngredientsIndex` currently extends Filament `TableComponent` and shows only `ownedByUser` ingredients.

The priced-ingredients section uses a **simple Blade table** below the existing Filament table. Since the section only needs name display and a single editable price field, a Filament table would be overkill. A plain Blade partial with `wire:model.blur` price inputs keeps it lightweight.

### Data Query

```php
UserIngredientPrice::query()
    ->where('user_id', $user->id)
    ->with('ingredient')
    ->orderBy('last_used_at', 'desc')
    ->get();
```

### Price Editing

A Livewire action on `IngredientsIndex`:
```php
public function updateIngredientPrice(int $ingredientId, string $pricePerKg): void
{
    // validate
    // upsert user_ingredient_prices
    // update last_used_at
}
```

The priced-ingredients section renders with `wire:model.blur` inputs for each price, similar to how the costing tab works.

## Ingredient Duplication

### User Flow

1. User opens the Ingredients page
2. A header action "Duplicate platform ingredient" (or similar wording) appears
3. User clicks it → a search/select modal opens listing all platform ingredients (where `owner_type` is null)
4. User selects an ingredient → confirmation
5. A new user-owned ingredient is created with all data copied from the platform ingredient (except images)
6. User is redirected to the ingredient editor for the new copy

### What Gets Copied

From the platform ingredient to the new user-owned copy:

| Data | Copied? | Notes |
|---|---|---|
| Identity (name, INCI, CAS, EC, supplier) | Yes | Via ingredient version |
| Category | Yes | |
| SAP profile (KOH, NaOH, fatty acids) | Yes | Deep copy |
| Composition (components) | Yes | Deep copy with same component ingredients |
| Allergens | Yes | Deep copy |
| IFRA certificates + limits | Yes | Deep copy |
| Images (featured, icon) | **No** | Platform image serves both |
| `is_organic` | Yes | |
| Functions (COSING) | Yes | |
| `is_potentially_saponifiable` | Yes | |
| `info_markdown` | Yes | |

The new ingredient record:
- `owner_type` = `OwnerType::User`
- `owner_id` = the user's id
- `visibility` = `Visibility::Private`
- `source_file` = `'user'`
- `source_key` = generated (USR prefix)
- `featured_image_path` = null
- `icon_image_path` = null
- `is_active` = true

### SAP Editing Constraint

If the duplicated ingredient is saponifiable (`is_potentially_saponifiable = true`), the user can edit the KOH SAP value but only within +/- 3% of the copied value.

This is enforced:
- In the ingredient editor: the SAP profile section shows the KOH value with a validation rule constraining edits to the threshold
- Validation message: "KOH SAP value must be within ±3% of the original value."

For non-saponifiable ingredients, the SAP profile section is hidden (current behavior).

### Search/Select Modal

The modal shows all active platform ingredients (where `owner_type` is null and `is_active` = true). Searchable by name and INCI. Each option shows:
- Name
- INCI (if available)
- Category badge

### No Traceability

The duplicated ingredient has no `source_ingredient_id` or reference back to the platform original. Once created, it is fully owned and maintained by the user.

### Service Method

Add a `duplicate` method to `UserIngredientAuthoringService`:

```php
public function duplicate(Ingredient $source, User $user): Ingredient
{
    // Create new ingredient record owned by user
    // Deep copy: version data, SAP profile, fatty acids, components, allergens, IFRA
    // Skip images
    // Return fresh ingredient
}
```

## Compliance Disclaimer

When a recipe contains user-owned ingredients, the compliance/output tab shows a visual indicator:

- A colored dot (amber or similar) next to each user-owned ingredient in the allergen declaration and compliance output
- Small text: "Ingredient not curated by the platform."
- This applies to all user-owned ingredients (both duplicated and created from scratch)

## Ingredients Page Layout

The Ingredients page (`/dashboard/ingredients`) has three sections in order:

1. **My ingredients** — existing Filament table (user-owned ingredients), unchanged. Header actions: "Add ingredient" (existing), "Duplicate platform ingredient" (new).
2. **Priced ingredients** — new section, only visible when the user has saved prices. Simple table with editable price/kg column.
3. The duplication modal, triggered by the header action.

## Testing

### Price Management
- Priced ingredients section shows only ingredients the user has priced
- Priced ingredients from other users are not visible
- Editing a price upserts `user_ingredient_prices` with updated `last_used_at`
- Section is hidden when user has no priced ingredients

### Duplication
- Duplicating a platform ingredient creates a user-owned copy with all data except images
- The copy has its own ingredient version, SAP profile, fatty acids, components, allergens, IFRA limits
- The copy does not reference the original ingredient
- The copy is editable by the user in the ingredient editor
- SAP value editing is constrained to +/- 3% for saponifiable ingredients
- Platform ingredients are searchable in the duplication modal
- Duplicate is scoped to the signed-in user

### Dead Columns
- `price_eur` and `display_name_en` are removed from the schema
- Model no longer references these columns
- Existing code that reads these columns is updated

### Disclaimer
- Compliance output shows the disclaimer indicator for user-owned ingredients
- Platform ingredients do not show the disclaimer

## Rollout Notes

- The dead-column migration is safe — `price_eur` is never read by the costing system and `display_name_en` is unused
- Duplication is additive — no existing data changes
- Price management is additive — reads from existing `user_ingredient_prices` data
- The SAP threshold validation only applies to user-owned saponifiable ingredients edited through the user ingredient editor
