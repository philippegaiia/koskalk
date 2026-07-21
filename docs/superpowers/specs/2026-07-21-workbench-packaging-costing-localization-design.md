# Workbench Packaging and Costing Localization Design

## Goal

Revise the English copy in the workbench Packaging and Costing tabs, then provide contextual French, Spanish, German, Italian, and Dutch translations through the existing database-backed interface translation system.

## Scope

The change covers every user-facing string owned by the two tabs: headings, explanatory copy, form labels, table headings, empty states, accessibility labels, modal actions, client-side messages, and Livewire responses. The packaging catalog pages remain unchanged because they are already localized separately.

The change does not alter costing calculations, packaging persistence, formula output documents, entitlements, the Filament admin, or the structure of the workbench.

## English terminology

Packaging uses practical product-making language:

- `Packaging plan`
- `Add the packaging components used for one finished unit. Enter their prices in Costing.`
- `Quantity per unit`
- `No packaging added yet.`
- `Create packaging item`
- `Save this item to your packaging library, then add it to this product now or later.`
- `Save to library`
- `Save and add`

Costing uses concise production language:

- `Costing setup`
- `Choose the quantity to cost, expected finished units, and currency.`
- `Oil quantity` for soap
- `Total batch quantity` for cosmetics
- `Finished units`
- `Your price / kg`
- `Ingredient costs`
- `Packaging costs`
- `Cost summary`
- `Enter finished units`
- `Saved automatically`, `Save the product first`, and `Could not save`

Soap phase labels use the approved terminology:

- `Saponification`
- `Formula additions`
- `Fragrance and aromatics`

User-authored cosmetic phase names remain unchanged.

## Translation ownership and flow

English source strings live under new `workbench.packaging.*` and `workbench.costing.*` keys in `lang/en/workbench.php`. Blade and Livewire resolve those keys through Laravel. The existing workbench JavaScript translation payload resolves the same keys client-side, including placeholder substitution.

`php artisan translations:sync` creates the database rows. A temporary, validated local import file fills only blank French, Spanish, German, Italian, and Dutch values. The importer must preserve placeholder names exactly and is removed after use. No locale PHP files, production seeder, or OpenAI API integration is added.

## Contextual translation standard

Translations convey the task and formulation context rather than matching English word-for-word. In particular, `finished units` means the number of saleable or usable finished items produced, `packaging library` means the user's reusable packaging catalog, and soap phase terms follow soap-making usage. Existing user-entered names and ingredient data are never translated as interface copy.

## Verification

Feature tests first lock the revised English copy and prove that database translations appear in both Packaging and Costing. Existing persistence tests continue to protect save behavior. After implementation, the focused test suite, frontend build, formatting, translation placeholder validation, and a browser review in all six locales must pass.
