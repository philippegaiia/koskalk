# Recipe Costing Design

## Summary

A dedicated Costing tab in the recipe workbench that lets users price their ingredients per kilogram, add packaging costs, and see batch economics (cost per unit, cost per kg) without cluttering the formula editing surface.

Ingredient identity is shared across all users. Prices are private to each user and stable once saved into a formula's costing state.

## Goals

- Separate formulation (building the recipe) from economics (understanding what it costs)
- Let users price any ingredient, including shared catalog ingredients, without duplicating the ingredient record
- Remember the last price entered per ingredient per user so prices do not need re-entering
- Keep a formula's costing stable: later changes to the user's default price do not silently rewrite existing costings
- Copy costing forward when publishing, duplicating, or restoring a version
- Reconcile costing rows when the formula structure changes (new rows, deleted rows, reordered rows)

## Non-Goals

- No costing history or snapshot comparison across saves
- No derived value storage (totals are always computed live)
- No margin targets, selling price, markup, or tax calculations in v1
- No supplier pack conversion or procurement workflow
- No "refresh from my defaults" button in v1 (may add later)

## Core Principle

> Ingredient identity is shared. Ingredient price is user-specific. Formula costing is stable until the user changes it.

## Schema

### `user_ingredient_prices`

Remembers the last price a user entered for an ingredient so it can be prefilled in future costings.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | Primary key |
| `user_id` | bigint | FK to users, cascade delete |
| `ingredient_id` | bigint | FK to ingredients, cascade delete |
| `price_per_kg` | decimal(10,4) | Nullable — user may not have priced this yet |
| `currency` | string(3) | Default 'EUR' |
| `last_used_at` | timestamp | Nullable, updated on each upsert |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Unique constraint on `(user_id, ingredient_id)`.

### `recipe_version_costings`

One costing context per user per recipe version. Created lazily when the user first opens the Costing tab.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | Primary key |
| `recipe_version_id` | bigint | FK to recipe_versions, cascade delete |
| `user_id` | bigint | FK to users, cascade delete |
| `oil_weight_for_costing` | decimal(12,3) | Nullable override; falls back to recipe batch size |
| `oil_unit_for_costing` | string(16) | Default 'g'. Options: g, kg, oz, lb |
| `units_produced` | unsigned int | Nullable. How many finished units the batch yields |
| `currency` | string(3) | Default 'EUR' |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Unique constraint on `(recipe_version_id, user_id)`.

### `recipe_version_costing_items`

The price per ingredient row currently used in a specific formula's costing. Independent of the user's global default price.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | Primary key |
| `recipe_version_costing_id` | bigint | FK to recipe_version_costings, cascade delete |
| `ingredient_id` | bigint | FK to ingredients, cascade delete |
| `phase_key` | string(64) | e.g. 'saponified_oils', 'additives', 'fragrance' |
| `position` | unsigned int | Row position within the phase |
| `price_per_kg` | decimal(10,4) | Nullable — may not be priced yet |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

Unique constraint on `(recipe_version_costing_id, ingredient_id, phase_key, position)`.

### `recipe_version_costing_packaging_items`

Packaging rows copied into a formula costing. Snapshotted from the catalog so historical costings stay readable.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | Primary key |
| `recipe_version_costing_id` | bigint | FK to recipe_version_costings, cascade delete |
| `user_packaging_item_id` | bigint | Nullable FK to user_packaging_items, null on delete |
| `name` | string | Snapshotted name |
| `unit_cost` | decimal(10,4) | Snapshotted unit cost |
| `quantity` | decimal(10,3) | Components per finished unit, default 1 |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

### `user_packaging_items`

Reusable packaging catalog owned by each user. Shared across all their recipes.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | Primary key |
| `user_id` | bigint | FK to users, cascade delete |
| `name` | string | e.g. 'Soap box', 'Amber jar' |
| `unit_cost` | decimal(10,4) | Effective unit price |
| `currency` | string(3) | Default 'EUR' |
| `notes` | text | Nullable |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

## User Interface

### Workbench Navigation

The workbench has four tabs in this order:

1. **Formula** — build the recipe composition
2. **Costing** — price ingredients and see batch economics
3. **Output** — review soap calculation results and allergens
4. **Instructions & Media** — manufacturing notes and images

The Costing tab is lazy-loaded: data is fetched from the backend only when the user first clicks the tab.

### Costing Tab Layout

The tab has four sections in vertical order:

1. **Costing Settings** — oil weight override, unit selector, units produced, currency display
2. **Ingredient Costing** — table with read-only formula rows and editable price/kg column
3. **Packaging** — table with add/remove packaging items, components per unit, unit price
4. **Cost Summary** — live-computed totals for ingredients, packaging, batch, per unit, per kg

### Ingredient Cost Table

Columns: Phase | Ingredient | % | Weight | Price/kg (editable) | Line cost

- Price/kg is prefilled from `user_ingredient_prices` if available
- Line cost = weight_in_kg × price_per_kg, computed live in the browser
- On save, the price is written to both the costing item AND upserted into `user_ingredient_prices`

### Packaging Section

Columns: Packaging item | Components per unit | Unit price | Cost per unit | Batch cost | Remove

- Users add existing catalog items via a picker
- Users create new catalog items via a modal with "Save and add" or "Save only"
- Defaults to 1 component per finished unit

### Cost Summary

Five summary cards:

- Ingredients total
- Packaging total
- Total batch cost
- Cost per unit (= total batch cost / units produced)
- Cost per kg (= total batch cost / batch weight in kg)

If units produced is not set, batch-dependent values show "Set units produced".

## Data Flow

### First Open

1. User clicks Costing tab
2. Frontend calls `loadCosting()` via Livewire
3. Backend calls `ensureCosting()` which creates the costing record if missing
4. `syncFormulaItems()` rebuilds costing items from the current formula rows, preserving existing prices and falling back to user defaults
5. Payload returned: settings, item prices, packaging items, packaging catalog

### Price Edit

1. User edits price/kg in the ingredient table
2. Frontend debounces (350ms) and calls `saveCosting()`
3. Backend `applyItemPrices()` saves the price to the costing item AND upserts `user_ingredient_prices` with `last_used_at = now()`
4. Response includes the full costing payload for reconciliation

### Formula Structure Change

When the formula changes (ingredient added, removed, or reordered):

1. Next `saveCosting()` or `ensureCosting()` call triggers `syncFormulaItems()`
2. All costing items are rebuilt from the current formula rows
3. Existing prices are preserved by matching `(ingredient_id, phase_key, position)`
4. New rows without a match get prefilled from `user_ingredient_prices`
5. Rows that no longer exist in the formula are deleted

### Version Lifecycle

When a recipe is published (`RecipeVersionPublisher::publish()`):

1. The current draft's costing is copied to the published version via `copyToVersion()`
2. The new draft version also receives the costing copy
3. Settings, item prices (matched by key), and packaging items are all forwarded
4. This ensures the user does not lose their costing work when publishing

### Derived Values

All totals are computed live in Alpine.js. No derived values are stored in the database:

- `line_cost = weight_in_kg × price_per_kg`
- `ingredient_total = sum of line costs`
- `packaging_cost_per_unit = components_per_unit × unit_price`
- `packaging_batch_cost = packaging_cost_per_unit × units_produced`
- `packaging_total = sum of packaging batch costs`
- `total_batch_cost = ingredient_total + packaging_total`
- `cost_per_unit = total_batch_cost / units_produced`
- `cost_per_kg = total_batch_cost / batch_weight_in_kg`

Weight conversion factors: g → 0.001 kg, kg → 1, oz → 0.02835 kg, lb → 0.45359 kg.

## Persistence

- `user_ingredient_prices`: upserted when a user saves a costing with a non-null price. `last_used_at` is always updated.
- `recipe_version_costings`: one row per (recipe_version, user). Created on first Costing tab open. Updated on settings or price changes.
- `recipe_version_costing_items`: rebuilt from formula on each sync, with existing prices preserved. Updated when user edits a price.
- `recipe_version_costing_packaging_items`: full replace on each save. Deleted and recreated from the submitted payload.
- Auto-save is debounced at 350ms. The Costing tab shows save status feedback.

## Error Handling

- If the user has not saved the first draft yet, costing shows "Save the first draft to keep ingredient prices"
- If a costing save fails, the frontend preserves user input and shows a non-destructive warning
- If a packaging catalog item is deleted, existing costing snapshots remain readable because name and unit_cost are snapshotted

## Testing

Coverage exists for:

- Prefilling from user price memory
- Saving formula costing while updating user memory
- Formula costing stability after user default price changes
- Copy-forward on version publish
- Legacy packaging quantity compatibility
- UI section order (settings → ingredients → packaging → summary)
- "Set units produced" fallback display
- Packaging row defaulting to 1 component per unit
- Creating packaging items from the catalog page
- Creating packaging items from the costing modal (save and add / save only)
- Packaging picker closing after add

## Future Considerations

These are explicitly deferred to later versions:

- "Refresh from my defaults" button to re-import current user prices into an existing costing
- Costing history or snapshot comparison
- Margin targets, selling price, markup, taxes
- Supplier pack conversion (e.g. "I buy 500g bags at X, derive per-kg price")
- Production batch records linking to costing snapshots
